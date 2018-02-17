<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use EasyWeChat\Factory;
use Log;
use App\Jaccount;

class WeChatController extends BaseController
{
    public function __construct()
    {
        $config = [
            'app_id' => env('WECHAT_APP_ID'),
            'secret' => env('WECHAT_SECRET'),

            // 指定 API 调用返回结果的类型：array(default)/collection/object/raw/自定义类名
            'response_type' => 'array',
        ];

        $app = Factory::officialAccount($config);
    }

    public function generateBindUrl($from)
    {
        $record = Jaccount::where('wechat_id', $from);
        if ($record->count() == 0) {
            $token = str_random(8);
            $jaccount = new Jaccount(array(
                'wechat_id' => $from,
                'jaccount' => '',
                'access_token' => '',
                'refresh_token' => '',
                'verify_token' => $token,
            ));
            $jaccount->save();
            return url('/jaccount/' . $token);
        } else {
            $record = $record->first();
            return url('/jaccount/' . $record->verify_token);
        }

    }

    public function messageHandler($content, $from)
    {
        $is_bind = Jaccount::where('wechat_id', $from)->where('jaccount', '<>', '')->count();
        if ($is_bind) {
            $jaccount_object = new JaccountApis($from);
            if (str_is('*校园卡*', $content)) {
                $card = $jaccount_object->getCardDetail();
                $user = $jaccount_object->user_detail;
                return "{$user->name} (校园卡号 {$card->cardNo})\n余额: {$card->cardBalance} 元\n过渡余额: {$card->transBalance} 元";
            } elseif (str_is('*课程*', $content) || str_is('*课表*', $content) || str_is('*课程表*', $content)) {
                $now = Carbon::now();
                $week = $now->diffInWeeks(Carbon::parse(env('SEMESTER_START')));
                $day = Carbon::now()->dayOfWeek;

                $tomorrow = max($day - 6, 0) * (-7) + $day + 1;
                $classes = $jaccount_object->getClasses($week, [$day, $tomorrow]);

                $weekday_str = ['一', '二', '三', '四', '五', '六', '日'];

                $today_class = "今日 (第{$week}周 周{$weekday_str[$day]}) 课程:";
                foreach ($classes[$day] as $class) {
                    $teacher = $class['teachers'][0]->name;
                    $classroom = $class['classroom'];
                    $class_time = join(', ', $class['class']);
                    $today_class .= "\n{$class['name']} @ {$classroom} ($teacher)\n第 {$class_time} 节";
                }

                $tomorrow_class = "明日 (第{$week}周 周{$weekday_str[$tomorrow]}) 课程:";
                foreach ($classes[$tomorrow] as $class) {
                    $teacher = $class['teachers'][0]->name;
                    $classroom = $class['classroom'];
                    $class_time = join(', ', $class['class']);
                    $tomorrow_class .= "\n{$class['name']} @ {$classroom} ($teacher)\n第 {$class_time} 节";
                }

                return "$today_class\n\n$tomorrow_class";
            } else {
                return "对不起，暂时不支持\"{$content}\"命令";
            }
        } else {
            $bind_url = $this->generateBindUrl($from);
            return "您尚未绑定 JAccount 账号。要使用全部服务，请到 {$bind_url} 进行绑定。";
        }
    }

    public function serve()
    {
        Log::info('request arrived.'); # 注意：Log 为 Laravel 组件，所以它记的日志去 Laravel 日志看，而不是 EasyWeChat 日志

        $app = app('wechat.official_account');
        $app->server->push(function($message){
            switch ($message['MsgType']) {
                case 'event':
                    break;
                case 'text':
                    return $this->messageHandler($message['Content'], $message['FromUserName']);
                    break;
                case 'image':
                    break;
                case 'voice':
                    return $this->messageHandler($message['Recognition'], $message['FromUserName']);
                    break;
                case 'video':
                    break;
                case 'location':
                    break;
                case 'link':
                    break;
                // ... 其它消息
                default:
                    break;
            }
        });

        return $app->server->serve();
    }
}

class JaccountApis
{
    public function __construct($wechat_id)
    {
        $this->jaccount = Jaccount::where('wechat_id', $wechat_id)->first();
        $this->refreshToken();
        $this->getUserDetail();
    }

    public function refreshToken()
    {
        $provider = new \League\OAuth2\Client\Provider\GenericProvider([
            'clientId'                => env('JACCOUNT_CLIENT_ID'),    // The client ID assigned to you by the provider
            'clientSecret'            => env('JACCOUNT_SECRET'),   // The client password assigned to you by the provider
            'redirectUri'             => url(),
            'urlAuthorize'            => 'https://jaccount.sjtu.edu.cn/oauth2/authorize',
            'urlAccessToken'          => 'https://jaccount.sjtu.edu.cn/oauth2/token',
            'urlResourceOwnerDetails' => 'https://api.sjtu.edu.cn/v1/me/profile'
        ]);

        $newAccessToken = $provider->getAccessToken('refresh_token', [
            'refresh_token' => $this->jaccount->refresh_token,
        ]);

        $this->jaccount->access_token = $newAccessToken->getToken();
        $this->jaccount->save();

    }

    public function getUserDetail()
    {
        $data = json_decode(file_get_contents('https://api.sjtu.edu.cn/v1/me/profile?access_token=' . $this->jaccount->access_token));
        $this->user_detail = ($data->entities)[0];
    }

    public function getCardDetail()
    {
        $data = json_decode(file_get_contents('https://api.sjtu.edu.cn/v1/me/card?access_token=' . $this->jaccount->access_token));
        return ($data->entities)[0];
    }

    public function getClasses($week = null, $days = [])
    {
        $data = json_decode(file_get_contents('https://api.sjtu.edu.cn/v1/me/lessons?access_token=' . $this->jaccount->access_token));
        $lessons = $data->entities;

        if ($week != null && $days != []) {
            $ret = [];
            foreach ($days as $day) {
                $ret[$day] = [];
            }
            foreach ($lessons as $lesson) {
                foreach ($lesson->classes as $class) {
                    if ($class->schedule->week == $week && in_array($class->schedule->day, $days)) {
                        if (array_key_exists($lesson->bsid, $ret[$class->schedule->day])) {
                            $ret[$class->schedule->day][$lesson->bsid]['class'][] = $class->schedule->period;
                        } else {
                            $ret[$class->schedule->day][$lesson->bsid] = array(
                                'name' => $lesson->name,
                                'teachers' => $lesson->teachers,
                                'class' => [$class->schedule->period],
                                'classroom' => $class->classroom->name,
                            );;
                        }
                    }
                }
            }
            return $ret;
        } else {
            return false;
        }
    }
}
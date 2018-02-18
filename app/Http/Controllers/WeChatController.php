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
use EasyWeChat\Kernel\Messages\News;
use EasyWeChat\Kernel\Messages\NewsItem;
use Illuminate\Support\Facades\Storage;
use EasyWeChat\Kernel\Messages\Image;
use Ixudra\Curl\Facades\Curl;

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
                'student_id' => '',
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

                $items = [
                    new NewsItem([
                        'title'       => '校园卡信息',
                        'description' => "{$user->name} (校园卡号 {$card->cardNo})\n\n余额: {$card->cardBalance} 元\n过渡余额: {$card->transBalance} 元",
                        'url'         => '',
                        'image'       => '',
                    ]),
                ];

                $news = new News($items);

                return $news;

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

                $items = [
                    new NewsItem([
                        'title'       => '我的课程',
                        'description' => "$today_class\n\n$tomorrow_class",
                        'url'         => '',
                        'image'       => '',
                    ]),
                ];

                $news = new News($items);

                return $news;

            } elseif (str_is('积分*', $content)) {
                $exp = str_replace("积分", "", str_replace(" ", "", $content));
                $contents = file_get_contents('http://127.0.0.1:5000/integrate?equation=' . urlencode($exp));
                $filename = md5(str_random()) . '.jpg';
                Storage::disk('public')->put($filename, $contents);

                $items = [
                    new NewsItem([
                        'title'       => '计算结果',
                        'description' => '',
                        'url'         => url('storage/' . $filename),
                        'image'       => url('storage/' . $filename),
                    ]),
                ];

                $news = new News($items);

                return $news;

            } elseif (str_is('求导*', $content)) {
                $lst = explode(' ', $content);
                $exp = $lst[1];
                if (count($lst) == 2) {
                    $times = 1;
                } else {
                    $times = (int)str_replace('阶', '', $lst[2]);
                }
                $contents = file_get_contents('http://127.0.0.1:5000/diff?equation=' . urlencode($exp) . '&times=' . $times);
                $filename = md5(str_random()) . '.jpg';
                Storage::disk('public')->put($filename, $contents);

                $items = [
                    new NewsItem([
                        'title'       => '计算结果',
                        'description' => '',
                        'url'         => url('storage/' . $filename),
                        'image'       => url('storage/' . $filename),
                    ]),
                ];

                $news = new News($items);

                return $news;

            } elseif (str_is('泰勒展开*', $content)) {
                $exp = str_replace("泰勒展开", "", str_replace(" ", "", $content));
                $contents = file_get_contents('http://127.0.0.1:5000/taylor?equation=' . urlencode($exp));
                $filename = md5(str_random()) . '.jpg';
                Storage::disk('public')->put($filename, $contents);

                $items = [
                    new NewsItem([
                        'title'       => '计算结果',
                        'description' => '',
                        'url'         => url('storage/' . $filename),
                        'image'       => url('storage/' . $filename),
                    ]),
                ];

                $news = new News($items);

                return $news;

            } elseif (str_is('*素拓*', $content) || str_is('*综合测评*', $content)) {
                $student_id = Jaccount::where('wechat_id', $from)->first()->student_id;

                $response = Curl::to('https://z.seiee.com/api/wechat/getScore')
                    ->withData(array(
                        'student_id' => $student_id,
                        'sign' => md5(md5($student_id) . '!SEIEE$' . Carbon::now()->toDateString())
                    ))
                    ->returnResponseObject()
                    ->post();

                $data = json_decode($response->content);

                $detail = [];
                foreach ($data->data->all_items as $item) {
                    $detail[] = "{$item->item_code} {$item->quality->category}: {$item->comment}\n{$item->quality->credit} × {$item->score}";
                }

                $items = [
                    new NewsItem([
                        'title'       => '综合测评',
                        'description' => $data->semester->year . '-' . ($data->semester->year + 1) . '学年 第' . $data->semester->semester .
                            "学期\n" . $data->data->student->name . ': ' . $data->data->score . '分 (' . $data->data->rank . "名)\n\n" . join("\n\n", $detail),
                        'url'         => "https://z.seiee.com/scores/search?student_no={$student_id}",
                    ]),
                ];

                $news = new News($items);

                return $news;

            } else {
                return "对不起，暂时不支持\"{$content}\"命令";
            }
        } else {
            $bind_url = $this->generateBindUrl($from);
            $items = [
                new NewsItem([
                    'title'       => '绑定 JAccount',
                    'description' => "您尚未绑定 JAccount 账号。要使用全部服务，请单击进行绑定 >>",
                    'url'         => $bind_url,
                    'image'       => '',
                ]),
            ];

            $news = new News($items);

            return $news;
        }
    }

    public function serve()
    {
        Log::info('request arrived.'); # 注意：Log 为 Laravel 组件，所以它记的日志去 Laravel 日志看，而不是 EasyWeChat 日志

        $app = app('wechat.official_account');

        $app->server->push(function($message) use (&$has_return) {
            switch ($message['MsgType']) {
                case 'event':
                    break;
                case 'text':
                    return $this->messageHandler($message['Content'], $message['FromUserName']);
                    break;
                case 'image':
                    break;
                case 'voice':
                    $has_return = 1;
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
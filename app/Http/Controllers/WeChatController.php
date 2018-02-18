<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Routing\Controller as BaseController;
use EasyWeChat\Factory;
use Log;
use App\Jaccount;
use EasyWeChat\Kernel\Messages\News;
use EasyWeChat\Kernel\Messages\NewsItem;
use Illuminate\Support\Facades\Storage;
use Ixudra\Curl\Facades\Curl;
use App\JaccountApis;

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

    /**
     * 生成绑定Jaccount的URL
     * @param $from
     * @return \Illuminate\Contracts\Routing\UrlGenerator|string
     */
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

    /**
     * 处理微信传来的消息
     * @param $content
     * @param $from
     * @return News|string
     */
    public function messageHandler($content, $from)
    {
        $is_bind = Jaccount::where('wechat_id', $from)->where('jaccount', '<>', '')->count();
        if ($is_bind) {
            if (str_is('*校园卡*', $content)) {
                return $this->cardHandler($content, $from);

            } elseif (str_is('*课程*', $content) || str_is('*课表*', $content) || str_is('*课程表*', $content)) {
                return $this->classTableHandler($content, $from);

            } elseif (str_is('积分*', $content)) {
                return $this->integrateHandler($content, $from);

            } elseif (str_is('求导*', $content)) {
                return $this->diffHandler($content, $from);

            } elseif (str_is('泰勒展开*', $content)) {
                return $this->taylorHandler($content, $from);

            } elseif (str_is('*素拓*', $content) || str_is('*综合测评*', $content)) {
                return $this->zhcpHandler($content, $from);

            } else {
                $recommand = ['校园卡', '课程表', '积分', '求导', '泰勒展开', '素拓'];
                $recommand = array_rand($recommand);

                return "对不起，暂时不支持\"{$content}\"命令。试试\"{$recommand}\"？";

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

    /**
     * 接收微信消息
     * @return mixed
     */
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
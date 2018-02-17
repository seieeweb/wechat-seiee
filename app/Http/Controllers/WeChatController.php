<?php

namespace App\Http\Controllers;

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
        if (str_is('*校园卡*', $content)) {
            if ($is_bind) {
                $jaccount_object = new JaccountApis($from);
                $card = $jaccount_object->getCardDetail();
                return json_encode($card);
            } else {
                $bind_url = $this->generateBindUrl($from);
                return "您尚未绑定 JAccount 账号, 请到 {$bind_url} 进行绑定。";
            }
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

    public function getCardDetail()
    {
        $data = json_decode(file_get_contents('https://api.sjtu.edu.cn/v1/me/card?access_token=' . $this->jaccount->access_token));
        return $data;
    }
}
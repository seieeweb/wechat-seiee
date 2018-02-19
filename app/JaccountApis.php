<?php
/**
 * Created by PhpStorm.
 * User: hebingchang
 * Date: 2018/2/18
 * Time: 下午7:42
 */

namespace App;

use Log;
use Carbon\Carbon;
use App\Card;
use App\User;

/**
 * Jaccount的接口们
 * Class JaccountApis
 * @package App\Http\Controllers
 */
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

    public function getCardTransaction($beginDate = null, $endDate = null)
    {
        if ($endDate == null) $endDate = Carbon::now()->getTimestamp();
        if ($beginDate == null) $beginDate = strtotime('today midnight');
        $data = json_decode(file_get_contents("https://api.sjtu.edu.cn/v1/me/card/transactions?beginDate={$beginDate}&endDate={$endDate}&access_token={$this->jaccount->access_token}"));

        $sum = 0;

        foreach ($data->entities as $entity)
        {
            if ($entity->amount < 0) {
                $sum -= $entity->amount;
            }
        }

        $card = Card::firstOrNew(array('wechat_id' => $this->jaccount->wechat_id, 'date' => Carbon::now()->toDateString()));
        $card->today_consumption = $sum;
        $card->save();

        return array(
            "sum" => $sum,
            'detail' => $data->entities,
        );
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
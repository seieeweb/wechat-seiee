<?php

namespace App\Http\Controllers;

use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use EasyWeChat\Factory;
use App\Jaccount;

class JaccountController extends BaseController
{
    public function __construct()
    {

    }

    public function bind($verify_token)
    {
        $record = Jaccount::where('verify_token', $verify_token);
        if ($record->count() == 0) {
            die('invalid token');
        } else {
            $provider = new \League\OAuth2\Client\Provider\GenericProvider([
                'clientId' => env('JACCOUNT_CLIENT_ID'),    // The client ID assigned to you by the provider
                'clientSecret' => env('JACCOUNT_SECRET'),   // The client password assigned to you by the provider
                'redirectUri' => url('/jaccount/' . $verify_token),
                'urlAuthorize' => 'https://jaccount.sjtu.edu.cn/oauth2/authorize',
                'urlAccessToken' => 'https://jaccount.sjtu.edu.cn/oauth2/token',
                'urlResourceOwnerDetails' => 'https://api.sjtu.edu.cn/v1/me/profile'
            ]);

            if (!isset($_GET['code'])) {
                $authorizationUrl = $provider->getAuthorizationUrl();
                $_SESSION['oauth2state'] = $provider->getState();
                header('Location: ' . $authorizationUrl);
                exit;
            } elseif (empty($_GET['state']) || (isset($_SESSION['oauth2state']) && $_GET['state'] !== $_SESSION['oauth2state'])) {
                if (isset($_SESSION['oauth2state'])) {
                    unset($_SESSION['oauth2state']);
                }
                exit('Invalid state');
            } else {
                try {
                    $record = $record->first();
                    $accessToken = $provider->getAccessToken('authorization_code', [
                        'code' => $_GET['code']
                    ]);

                    $record->access_token = $accessToken->getToken();
                    $record->refresh_token = $accessToken->getRefreshToken();
                    $data = json_decode(file_get_contents('https://api.sjtu.edu.cn/v1/me/profile?access_token=' . $record->access_token));
                    $record->jaccount = ($data->entities)[0]->account;
                    $record->student_id = ($data->entities)[0]->code;

                    $record->save();

                    return view('success');

                } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {

                    // Failed to get the access token or user details.
                    exit($e->getMessage());

                }


            }
        }
    }

}

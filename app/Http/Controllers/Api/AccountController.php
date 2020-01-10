<?php


namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseController;
use App\Service\Disk;
use Illuminate\Http\Request;

/**
 * 账号授权
 * Class AccountController
 * @package App\Http\Controllers\Api
 */
class AccountController extends BaseController
{
    /**
     * SettingController constructor.
     */
    public function __construct()
    {
        $this->middleware('token.refresh', ['except' => ['callback']]);
        $this->middleware('jwt.auth', ['except' => ['callback']]);
    }

    /**
     * 跳转申请
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function apply(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'redirect_uri' => 'required',
        ]);
        if ($validator->fails()) {
            return $this->errorBadRequest($validator);
        }
        $redirect_uri = $request->get('redirect_uri');
        $ru = 'https://developer.microsoft.com/en-us/graph/quick-start?appID=_appId_&appName=_appName_&redirectUrl='
            . $redirect_uri . '&platform=option-php';
        $deepLink = '/quickstart/graphIO?publicClientSupport=false&appName=OLAINDEX&redirectUrl='
            . $redirect_uri . '&allowImplicitFlow=false&ru='
            . urlencode($ru);
        $redirect = 'https://apps.dev.microsoft.com/?deepLink=' . urlencode($deepLink);
        return $this->success([
            'redirect' => $redirect
        ]);
    }

    /**
     * 跳转绑定
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function bind(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'account_type' => 'required',
            'client_id' => 'required',
            'client_secret' => 'required',
            'redirect_uri' => 'required',
            'redirect' => 'required',
        ]);
        if ($validator->fails()) {
            return $this->errorBadRequest($validator);
        }
        $redirect_uri = $request->get('redirect_uri');
        $redirect = $request->get('redirect');
        $data = [
            'account_type' => $request->get('account_type'),
            'client_id' => $request->get('client_id'),
            'client_secret' => $request->get('client_secret'),
            'redirect_uri' => $redirect_uri
        ];

        $slug = str_random();
        $accountCache = array_merge($data, ['redirect' => $redirect]);
        setting_set('account', $data);
        \Cache::add($slug, $accountCache, 15 * 60); //15分钟内需完成绑定否则失效
        $state = $slug;
        if (str_contains($redirect_uri, 'olaindex.github.io')) {
            $state = base64_encode(json_encode([
                $slug,
                $request->getSchemeAndHttpHost() . '/api/account/callback'
            ])); // 拼接state
        }

        $authorizeUrl = Disk::authorize()->getAuthorizeUrl($state);

        return $this->success([
            'redirect' => $authorizeUrl
        ]);
    }

    /**
     * 解绑
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Exception
     */
    public function unbind(Request $request)
    {
        setting_set('account', []);

        return $this->success([]);
    }

    /**
     * 回调
     * @param Request $request
     * @return mixed
     * @throws \ErrorException
     */
    public function callback(Request $request)
    {
        $state = $request->get('state', '');
        $code = $request->get('code', '');

        if (!$state || !\Cache::has($state)) {
            \Cache::forget($state);
            return $this->fail('Invalid state');
        }
        $accountCache = \Cache::get($state);
        $token = Disk::authorize()->getAccessToken($code);
        $token = $token->toArray();
        \Log::info('access_token', $token);
        $access_token = array_get($token, 'access_token');
        $refresh_token = array_get($token, 'refresh_token');
        $expires = array_has($token, 'expires_in') ? time() + array_get($token, 'expires_in') : 0;
        $access_token_expires = date('Y-m-d H:i:s', $expires);
        $account = array_merge(setting('account'), [
            'access_token' => $access_token,
            'refresh_token' => $refresh_token,
            'access_token_expires' => $access_token_expires,
        ]);
        setting_set('account', $account);
        refresh_account();
        $redirect = array_get($accountCache, 'redirect', '/');
        return redirect()->away($redirect);
    }

    /**
     * 账户详情
     * @return mixed
     */
    public function info()
    {
        $info = collect(setting('account.extend'))->only(['owner', 'quota']);

        return $this->success($info->all());
    }
}

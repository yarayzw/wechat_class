<?php
/**
 * Created by PhpStorm.
 * User: yara
 * Date: 2019-03-23
 * Time: 09:56
 */
namespace Home\Controller;

/**
 * 微信小程序接口
 * Class WechatMiniController
 * @package Home\Controller
 */
class WechatMini extends Common
{
    /**
     * 微信统一下单
     * @param int $fee               价格 单位: 元
     * @param string $openid         用户唯一标识
     * @param null $out_trade_no     商品订单编号
     * @param string $body           商品描述
     * @return mixed
     */
    public function jsWechat($fee, $openid, $out_trade_no = null, $body = '商品')
    {
        $post = array(
            'appid' => WechatConfig::APP_ID,
            'mch_id' => WechatConfig::MCH_ID,
            'nonce_str' => $this->nonceStr(),
            'body' => $body,
            'out_trade_no' => $out_trade_no ? $out_trade_no : $this->orderStr($openid),
            'total_fee' => $fee,
            'spbill_create_ip' => $_SERVER['REMOTE_ADDR'] == '::1' ? '127.0.0.1' : $_SERVER['REMOTE_ADDR'],
            'notify_url' => 'http://www.weixin.qq.com/wxpay/pay.php',
            'trade_type' => 'JSAPI',
            'openid' => $openid
        );
        ksort($post);
        $sign = strtoupper(md5(urldecode(http_build_query($post) . '&key=' . WechatConfig::KEY)));
        $post['sign'] = $sign;
        $data = $this->arrayToXml($post);
        $return_data = $this->httpPost('https://api.mch.weixin.qq.com/pay/unifiedorder', $data, false);
        return json_decode(json_encode(simplexml_load_string($return_data, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
    }
    /**
     * 微信支付接口
     * @param int $fee              价格 单位: 分
     * @param string $openid        用户openid
     * @param null $out_trade_no    订单编号
     * @param string $body          商品描述
     * @return mixed
     */
    public function payWechat($fee = 1, $openid = 'oAfYU0V7qtFYt_oRvbt2vB13Ln1E', $out_trade_no = null, $body = '商品')
    {
        $return_data = $this->jsWechat($fee * 100, $openid, $out_trade_no, $body);
        $res['timeStamp'] = (string)time();
        $res['nonceStr'] = $return_data['nonce_str'];
        $res['package'] = 'prepay_id=' . $return_data['prepay_id'];
        $res['signType'] = 'MD5';
        $sign_data = array(
            'appId' => WechatConfig::APP_ID,
            'timeStamp' => $res['timeStamp'],
            'nonceStr' => $return_data['nonce_str'],
            'package' => $res['package'],
            'signType' =>'MD5'
        );
        ksort($sign_data);
        $sign = md5(urldecode(http_build_query($sign_data) . '&key=' . WechatConfig::KEY));
        $res['paySign'] = $sign;
        return $res;
    }
    /**
     * 随机32位字符串
     * @return string
     */
    private function nonceStr()
    {
        $result = '';
        $str = 'QWERTYUIOPASDFGHJKLZXVBNMqwertyuioplkjhgfdsamnbvcxz';
        for ($i = 0; $i < 32; $i++) {
            $result .= $str[rand(0, 48)];
        }
        return $result;
    }
    /**
     * 伪装的订单id
     * @param string $openid  用户openid
     * @return string
     */
    private function orderStr($openid)
    {
        $result = md5($openid . time() . rand(10, 99));
        return $result;
    }
    public function notify()
    {
    }
    /**
     * 获取用户openid
     * @return bool|mixed|string
     */
    public function getOpenid()
    {
        if (isset($_POST['code'])) {
            $code = $_POST['code'];
            $app_id = WechatConfig::APP_ID;
            $app_secret = WechatConfig::APP_SECRET;
            $response = $this->httpGet("https://api.weixin.qq.com/sns/jscode2session?appid={$app_id}&secret={$app_secret}&js_code={$code}&grant_type=authorization_code");
            $response = json_decode($response, true);
            if ($response['openid'] && $response['session_key']) {
                return $response;
            }
        }
        $this->ajaxReturn(10001, '', '获取信息失败');
    }
    /**
     * 获取用户unionId
     * @return int|string
     */
    public function getUnionId()
    {
        $openData = $this->getOpenid();
        $iv = $_POST['iv'];
        $encryptedData = $_POST['encryptedData'];
        $return_data = $this->decryptData($encryptedData, $iv, $openData['session_key']);
        return $return_data;
    }
    /**
     * 检验数据的真实性，并且获取解密后的明文.
     * @param string $encryptedData  加密的用户数据
     * @param string $iv             与用户数据一同返回的初始向量
     * @param string $session_key
     * @return int | string          失败返回对应的错误码
     */
    public function decryptData($encryptedData, $iv, $session_key)
    {
        if (strlen($session_key) != 24) $this->ajaxReturn(10001, '', 'encodingAesKey 非法');
        $aesKey = base64_decode($session_key);
        if (strlen($iv) != 24) $this->ajaxReturn(10001, '', 'aes 解密失败');
        $aesIV = base64_decode($iv);
        $aesCipher = base64_decode($encryptedData);
        $result = openssl_decrypt($aesCipher, "AES-128-CBC", $aesKey, 1, $aesIV);
        $dataObj = json_decode($result);
        if ($dataObj == NULL) $this->ajaxReturn(10001, '', 'base64加密失败');
        if ($dataObj->watermark->appid != WechatConfig::APP_ID) $this->ajaxReturn(10001, '', 'base64加密失败');
        $data = $result;
        return $data;
    }
}
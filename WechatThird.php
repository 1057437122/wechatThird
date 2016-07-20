<?php
/**
* 微信开放平台第三方平台PHP-SDK
* @author libaishun007@gmail.com
* 消息处理  授权处理 
* 引导授权
* 微信开放平台php sdk
*网上很多微信公众平台的sdk，但是开放平台的不多，正好工作时用到了，就整理一份~
*使用方法：
*1、首先把需要的参数 传递进来
*  $info = array('appid'=>'xxxxx','appsecret'=>'xxxxx','component_verify_ticket'=>'xxxxx');
*  $info['debug'] = true;//是否调试
*  $info['logcallback'] = 'trace';//写日志的回调函数，使用者可以自己定义，如TP中的trace
*  $info['cachedcallback'] = array($this,'getCacheParam');//读取缓存数据的方法，与微信通讯的许多token都需要缓存的，所以这个方法建议使用者一定自己定义，函数格式为function getCacheParam('component_access_token')即为读取缓存的component_access_token的方法，注意，这个自己定义的方法必须判断数据是否过期，若过期必须返回false
*  $info['cachecallback']  = array($this,'cacheParam');//与上面类似，这个是缓存数据的方法，缓存的格式是function cacheParam(array('item'=>'component_access_token','data'=>array('expires_in'=>7200,'component_access_token'=>'xxxxx',)))，缓存时注意收到数据的时间
*  $info['authorizer_appid']= isset($_GET['appid']) ? $_GET['appid'] : '';//微信平台向使用者定义的公众号消息与事件接收URL发送数据时，会将$appid传过来，授权事件不需要此参数
*  $info['authorizer_refresh_token'] ='';//如果已经授权完成，那么此参数必填，否则无法刷新 authorizer_access_token，授权事件不需要此参数
*2、接着实例化这个类
*$wechatObj = new WechatThird($info);
*3、
*1>若是授权事件接收的方法则：(每10分钟推送的ticket也会传到这个方法上，所以需要对ticket进行处理)
*$InfoType = $wechatObj->getRev()->getRevAuthInfoType();
*switch( $InfoType ){
*			case WechatThird::INFOTYPE_AUTHORIZED:
*			//授权事件
*			//codes..
*			case WechatThird::INFOTYPE_UPDATEAUTHORIZED:
*			//更新授权事件
*			//codes..
*			case WechatThird::INFOTYPE_UNAUTHORIZED:
*			//取消授权
*			//codes...
*			default:
*				//可以在这里对ticket进行处理
*	}
*2>若是推送公众号的消息事件接收方法，则可以根据收到的消息类型进行处理
*$MsgType = $wechatObj->getRev()->getRevType();
*		switch( $MsgType ){
*			case WechatThird::MSGTYPE_EVENT:
*			//事件类型
*				$event = $wechatObj->getRev()->getRevEvent();
*				$data = array(
*					'wechat_origin_id' 	=> $wechatObj->getRevFrom(),
*					'open_id'			=> $wechatObj->getRevTo(),
*				);
*				switch ( $event ){
*					case WechatThird::EVENT_UNSUBSCRIBE:
*					//codes*
*					case ...
*				}
*			case WechatThird::MSGTYPE_TEXT:
*			//文本类型
*			//codes...
*			
*		}
*
*/

include_once "WechatEncrypt/WXBizMsgCrypt.php";

class WechatThird{

	//消息类型
	const MSGTYPE_TEXT = 'text';
	const MSGTYPE_IMAGE = 'image';
	const MSGTYPE_LOCATION = 'location';
	const MSGTYPE_LINK = 'link';
	const MSGTYPE_EVENT = 'event';
	const MSGTYPE_MUSIC = 'music';
	const MSGTYPE_NEWS = 'news';
	const MSGTYPE_VOICE = 'voice';
	const MSGTYPE_VIDEO = 'video';

	//授权事件 授权 取消授权 更新授权
	const INFOTYPE_AUTHORIZED = 'authorized';
	const INFOTYPE_UPDATEAUTHORIZED = 'updateauthorized';
	const INFOTYPE_UNAUTHORIZED = 'unauthorized';

	//用户事件 关注 取关等
	const EVENT_UNSUBSCRIBE = 'unsubscribe';
	const EVENT_SUBSCRIBE = 'subscribe';

	//links
	const API_URL_PREFIX = 'https://api.weixin.qq.com/cgi-bin';
	const MP_URL_PREFIX = 'https://mp.weixin.qq.com/cgi-bin';
	const COMPONENT_TOKEN_URL = '/component/api_component_token';
	const AUTHORIZER_TOKEN_URL = '/component/api_query_auth?';
	const CUSTOM_SEND_URL='/message/custom/send?';
	const API_AUTHORIZER_REFRESH_URL = '/component/api_authorizer_token?';
	const API_CREATE_PREAUTHCODE_URL = '/component/api_create_preauthcode?';
	const COMPONENTLOGINPAGE = '/componentloginpage?';



	private $token;
	private $encodingaeskey;
	private $appid;//第三方平台 appid
	private $appsecret;

	private $_preauthcode;//预授权码

	private $authorizer_appid;//授权方公众号 appid

	private $component_verify_ticket;//wechat server 推送给第三方后台填写的网址
	//以下三个参数 使用者都应该自己定义读取缓存数据和写入缓存数据的方法，因为 都有有效期
	private $_component_access_token;
	private $_authorizer_access_token;

	//refresh token 有效期为无限，故只需要妥善保存就行在进行消息处理的调用函数上必须 给这个值，否则 无法更新authorizer_access_token ，导致无法调用微信API 
	private $_authorizer_refresh_token;

	private $_receive;//解析成array结构的接收到的数据
	private $_msg;//解密后的数据，一般为xml
	private $_msg_to_send;//要发送的数据 
	private $encryptMsg;//加密后的要发送的数据 

	private $debug;
	private $_logcallback;//使用者写日志的方法
	private $_text_filter = true;
	private $_funcflag = false;
	private $_cachedcallback;//使用者获取缓存数据的方法 需要自己判断 数据 是否可用 （需要留一定的时间长度限制，比如说微信规定过期时间为7200秒，则实际上到了7000秒就可以判断 为过期）可用时则直接返回相应数据 ，不可用时返回false
	private $_cachecallback;//使用者缓存 数据 到自己的服务器的方法 ，数据获取的时间戳为当前时间戳即可
	
	public function __construct($info){
		$this->token 			= isset( $info['token'] ) 			? $info['token'] : '' ;
		$this->encodingaeskey 	= isset( $info['encodingaeskey'] ) 	? $info['encodingaeskey'] : '' ;
		$this->appid 			= isset( $info['appid'] ) 			? $info['appid'] : '' ;
		$this->authorizer_appid	= isset( $info['authorizer_appid'] )? $info['authorizer_appid'] : '' ;
		$this->appsecret 		= isset( $info['appsecretkey'] ) 	? $info['appsecretkey'] : '' ;
		$this->component_verify_ticket	= isset( $info['component_verify_ticket'] )	? $info['component_verify_ticket'] : '';
		$this->debug  			= isset( $info['debug'] ) 			? $info['debug'] : '' ;
		$this->_logcallback 	= isset( $info['logcallback'] )		? $info['logcallback'] : false;
		$this->_cachedcallback	= isset( $info['cachedcallback'] )	? $info['cachedcallback'] : false;
		$this->_cachecallback	= isset( $info['cachecallback'] )	? $info['cachecallback'] : false;
		$this->_authorizer_refresh_token = isset( $info['authorizer_refresh_token'] ) ? $info['authorizer_refresh_token']  : false;
	}

	private function log($log){
    		if ($this->debug && function_exists($this->_logcallback)) {
    			if (is_array($log)) $log = json_encode($log);
    			return call_user_func($this->_logcallback,$log);
    		}
    }
    private function decryptRevMsg($postData){
    	$msgObj = new WXBizMsgCrypt( $this->token,$this->encodingaeskey,$this->appid );
		$signature 		= isset( $_GET['signature']		) ? $_GET['signature'] 		: '';
		$timestamp		= isset( $_GET['timestamp']		) ? $_GET['timestamp'] 		: '';
		$nonce			= isset( $_GET['nonce']			) ? $_GET['nonce'] 			: '';
		$encrypt_type 	= isset( $_GET['encrypt_type']	) ? $_GET['encrypt_type'] 	: '';
		$msg_signature 	= isset( $_GET['msg_signature']	) ? $_GET['msg_signature'] 	: '';

		if( empty( $msg_signature ) ){
			return false;
		}
		
		$errcode = $msgObj->decryptMsg($msg_signature,$timestamp,$nonce,$postData,$this->_msg);

		return $errcode;//返回0表示正确

    }
    private function encryptSendMsg($msg_xml){
    	$msgObj = new WXBizMsgCrypt( $this->token,$this->encodingaeskey,$this->appid );
    	$timestamp		= isset( $_GET['timestamp']		) ? $_GET['timestamp'] 		: '';
		$nonce			= isset( $_GET['nonce']			) ? $_GET['nonce'] 			: '';
    	$errcode = $msgObj->encryptMsg($msg_xml, $timestamp, $nonce, $this->encryptMsg);
    	return $errcode;
    }

	/**
     * 获取微信服务器发来的信息
     */
	public function getRev(){
		if ($this->_receive) return $this;
		$postStr = file_get_contents("php://input");
		$this->log($postStr);
		if ( !empty( $postStr ) ) {
			//解密 
			$errcode = $this->decryptRevMsg($postStr);
			if ( $errcode === 0 ){
				//解密 成功 
				$this->_receive = json_decode(json_encode(simplexml_load_string($this->_msg,'SimpleXMLElement',LIBXML_NOCDATA)),true);
			}

			
		}
		return $this;
	}

	/**
	* 获取 ComponentVerifyTicket
	*/
	public function getRevComponentVerifyTicket(){
		if ( isset( $this->_receive['ComponentVerifyTicket'] ) )
			return $this->_receive['ComponentVerifyTicket'];
		else
			return false;
	}

	/**
	 * 获取消息发送者
	 */
	public function getRevFrom() {
		if (isset($this->_receive['FromUserName']))
			return $this->_receive['FromUserName'];
		else 
			return false;
	}
	
	/**
	 * 获取消息接受者
	 */
	public function getRevTo() {
		if (isset($this->_receive['ToUserName']))
			return $this->_receive['ToUserName'];
		else 
			return false;
	}
	
	/**
	 * 获取接收消息的类型
	 */
	public function getRevType() {
		if (isset($this->_receive['MsgType']))
			return $this->_receive['MsgType'];
		else 
			return false;
	}

	/**
	 * 获取接收的事件
	 */
	public function getRevEvent() {
		if (isset($this->_receive['Event']))
			return $this->_receive['Event'];
		else 
			return false;
	}

	/**
	* 获取授权事件类型
	*/
	public function getRevAuthInfoType(){
		if ( isset( $this->_receive['InfoType'] ) )
			return $this->_receive['InfoType'];
		else
			return false;
	}

	/**
	* 获取授权账号信息 AuthorizerAppid
	*/
	public function getRevAuthAppid(){
		if ( isset($this->authorizer_appid ) && !empty( $this->authorizer_appid ) ) //构造时直接传入的第三方授权公众号的appid 处理推送的消息时使用此方法
			return $this->authorizer_appid;
		if ( isset($this->_receive['AuthorizerAppid'] ) )//接收到微信推送的第三方授权公众号的appid 授权推送给平台 时使用此方法
			return $this->_receive['AuthorizerAppid'];
		else
			return false;
	}

	/**
	* 获取授权账号信息 AuthorizationCode 授权事件时会有此参数
	*/
	public function getRevAuthCode(){
		if ( isset($this->_receive['AuthorizationCode'] ) )
			return $this->_receive['AuthorizationCode'];
		else
			return false;
	}

	/**
	 * 获取接收消息内容正文
	 */
	public function getRevContent(){
		if (isset($this->_receive['Content']))
			return $this->_receive['Content'];
		else if (isset($this->_receive['Recognition'])) //获取语音识别文字内容，需申请开通
			return $this->_receive['Recognition'];
		else
			return false;
	}
	/*
	* 获取自己的信息 authorizer_access_token
	*/
	public function getSelfAuthorizerAccessToken(){
		if( isset($this->_authorizer_access_token) )
			return $this->_authorizer_access_token;
		else
			return false;
	}
	/*
	* 获取 自己的authorizer_refresh_token
	*/
	public function getSelfAuthorizerRefreshToken(){
		return $this->_authorizer_refresh_token;
	}
	/**
	* 
	*/
	public static function xmlSafeStr($str)
	{
		return '<![CDATA['.preg_replace("/[\\x00-\\x08\\x0b-\\x0c\\x0e-\\x1f]/",'',$str).']]>';
	}

	/**
	 * 数据XML编码
	 * @param mixed $data 数据
	 * @return string
	 */
	public static function data_to_xml($data) {
	    $xml = '';
	    foreach ($data as $key => $val) {
	        is_numeric($key) && $key = "item id=\"$key\"";
	        $xml    .=  "<$key>";
	        $xml    .=  ( is_array($val) || is_object($val)) ? self::data_to_xml($val)  : self::xmlSafeStr($val);
	        list($key, ) = explode(' ', $key);
	        $xml    .=  "</$key>";
	    }
	    return $xml;
	}

	/**
	 * XML编码
	 * @param mixed $data 数据
	 * @param string $root 根节点名
	 * @param string $item 数字索引的子节点名
	 * @param string $attr 根节点属性
	 * @param string $id   数字索引子节点key转换的属性名
	 * @param string $encoding 数据编码
	 * @return string
	*/
	public function xml_encode($data, $root='xml', $item='item', $attr='', $id='id', $encoding='utf-8') {
	    if(is_array($attr)){
	        $_attr = array();
	        foreach ($attr as $key => $value) {
	            $_attr[] = "{$key}=\"{$value}\"";
	        }
	        $attr = implode(' ', $_attr);
	    }
	    $attr   = trim($attr);
	    $attr   = empty($attr) ? '' : " {$attr}";
	    $xml   = "<{$root}{$attr}>";
	    $xml   .= self::data_to_xml($data, $item, $id);
	    $xml   .= "</{$root}>";
	    return $xml;
	}

	/**
	 * 过滤文字回复\r\n换行符
	 * @param string $text
	 * @return string|mixed
	 */
	private function _auto_text_filter($text) {
		if (!$this->_text_filter) return $text;
		return str_replace("\r\n", "\n", $text);
	}

	/**
	* 到微信服务器去取component access token
	* 先调用缓存的数据（使用自己的定义方法），若过期则去微信服务器取新的
	*/
	public function getComponentAccessToken(){
		$tmp = '';
		try{
			$tmp = call_user_func( $this->_cachedcallback,'component_access_token' );
			$this->log('got component access token from cached data~');
		}catch(EXCEPTION $e){
			$tmp = '';
			$this->log('fail to get cached component access token:'.json_encode($e));
		}
		if( !$tmp ){
			//去微信服务器取
			$request_url = self::API_URL_PREFIX.self::COMPONENT_TOKEN_URL;
			$post_arr = array(
				'component_appid'    		=>$this->appid,
				'component_appsecret' 		=>$this->appsecret,
				'component_verify_ticket'   =>$this->component_verify_ticket,
			);
	
			$data = $this->http_post($request_url,json_encode($post_arr));
			$ret = json_decode($data,true);
			if( !isset( $ret['errcode'] ) ){
				//返回成功
				$tmp = $ret['component_access_token'];
				try{
					//调用自己的缓存 数据 的方法用来缓存 component_access_token
					call_user_func( $this->_cachecallback, array( 'item'=>'compoment_access_token','data'=>$ret ) );
				}catch(EXCEPTION $e){
					$this->log('error on cache component access token:'.json_encode($e));
				}
			}else{
				$this->log('fail to get component access token from wechat server ....');
			}
		}
		$this->_component_access_token = $tmp;
		return $tmp;//component_access_token
		
	}
	/**
	* 引导至授权页面
	*/
	public function pushToAuth($call_back_url){
		$this->getPreCode();
		//https://mp.weixin.qq.com/cgi-bin/componentloginpage?component_appid=xxxx&pre_auth_code=xxxxx&redirect_uri=xxxx
		redirect(self::MP_URL_PREFIX.self::COMPONENTLOGINPAGE.'component_appid='.$this->appid.'&pre_auth_code='.$this->_preauthcode.'&redirect_uri='.$call_back_url);
	}

	/*
	* 获取预授权码
	*/
	public function getPreCode(){
		//https://api.weixin.qq.com/cgi-bin/component/api_create_preauthcode?component_access_token=xxx
		$this->getComponentAccessToken();
		$request_url = self::API_URL_PREFIX.self::API_CREATE_PREAUTHCODE_URL.'component_access_token='.$this->_component_access_token;
		$data_to_send = json_encode(array('component_appid'=>$this->appid));
		$ret = $this->http_post($request_url,$data_to_send);
		$json_ret = json_decode( $ret ,true);
		if( !$json_ret || !empty($json_ret['errcode'] ) ){
			echo '授权失败';
			$this->log('Fail to Auth ...');
			return false;
		}
		$this->_preauthcode = $json_ret['pre_auth_code'];
		return $this->_preauthcode;
	}
	/*
	* 方法需要优化 
	* 授权时通过授权码去获取 access token 和refresh token
	* 使用者必须 自己手动 缓存  authorizer_refresh_token 
	* return 
	*/
	public function getAuthorizerToken(){
		$this->getComponentAccessToken();
		//https://api.weixin.qq.com/cgi-bin/component/api_query_auth?component_access_token=xxxx
		$request_url = self::API_URL_PREFIX.self::AUTHORIZER_TOKEN_URL.'component_access_token='.$this->_component_access_token;
		$code = $this->getRevAuthCode();
		$data_to_send = json_encode(array('component_appid'=>$this->appid,'authorization_code'=>$code ) );
		$ret = $this->http_post($request_url,$data_to_send);
		$this->log('get authorizer_access_token and refresh token by auth code:'.$ret);
		$json_ret = json_decode($ret,true);
		if( !$json_ret || !empty($json_ret['errcode'] ) ){
			return false;
		}
		$this->_authorizer_access_token  = $json_ret['authorization_info']['authorizer_access_token'];
		$this->_authorizer_refresh_token = $json_ret['authorization_info']['authorizer_refresh_token'];
		try{
			call_user_func( $this->_cachecallback,array('item'=>'authorizer_access_token','data'=>array(
				'expires_in'=>$json_ret['authorization_info']['expires_in'],
				'authorizer_access_token'=>$json_ret['authorization_info']['authorizer_access_token'],
				)));
			call_user_func( $this->_cachecallback,array('item'=>'authorizer_refresh_token','data'=>array(
				'authorizer_refresh_token'=>$json_ret['authorization_info']['authorizer_refresh_token'],
				)));
		}catch(EXCEPTION $e){
			$this->log('fail to cache authorizer access token or refresh token '.json_encode($e));
		}
		return $json_ret;
	}

	/*
	* 此去确定一个有效的authorizer_access_token
	* 如果没有缓存的就 通过refresh token 去刷新 
	* 执行此处时必定已经授权完成并且在构造时初始了authorizer_appid和authorizer_refresh_token
	*/
	private function checkAuth(){
		if( !$this->_authorizer_refresh_token ) 
			return false;
		$cached_a_t = '';
		try{
			$cached_a_t = call_user_func( $this->_cachedcallback, 'authorizer_access_token',$this->authorizer_appid );
			$this->_authorizer_access_token = $cached_a_t;
			$this->log('got old authorized access token ');
		}catch(EXCEPTION $e){
			$this->log('error on get cached authorizer access toke:'.json_encode($e));
			$cached_a_t = '';
		}
		if( !$cached_a_t ){
			//通过refresh token 去微信服务器刷新 access token 
			//https:// api.weixin.qq.com /cgi-bin/component/api_authorizer_token?component_access_token=xxxxx
			$this->getComponentAccessToken();
			$request_url = self::API_URL_PREFIX.self::API_AUTHORIZER_REFRESH_URL.'component_access_token='.$this->_component_access_token;
			$data_to_send = json_encode( 
				array(
					'component_appid'			=> $this->appid,
					'authorizer_appid'			=> $this->getRevAuthAppid(),
					'authorizer_refresh_token'	=> $this->_authorizer_refresh_token,
				) );
			$ret = $this->http_post( $request_url,$data_to_send );
			$this->log('get accesstoken :'.$ret) ;
			$json_ret = json_decode($ret,true);
			if( !$json_ret || !empty($json_ret['errcode'] ) ){
				//@todu 记录错误参数 以便处理等
				return false;
			}
			$this->_authorizer_access_token = $json_ret['authorizer_access_token'];
			$cached_a_t = $this->_authorizer_access_token;
			try{
				//缓存 access_token
				call_user_func( $this->_cachecallback , array('item'=>'authorizer_access_token','data'=>$json_ret ) ,$this->getRevAuthAppid());
			}catch(EXCEPTION $e){
				$this->log('fail to cache new authorizer access token:'.json_encode($e));
			}

		}
		return $cached_a_t;
	}

	/**
	 * 回复 文本消息
	 * Examle: $obj->text('hello')->reply();
	 * @param string $text
	 */
	public function text($text)
	{
		$FuncFlag = $this->_funcflag ? 1 : 0;
		$msg = array(
			'ToUserName' => $this->getRevFrom(),
			'FromUserName'=>$this->getRevTo(),
			'MsgType'=>self::MSGTYPE_TEXT,
			'Content'=>$this->_auto_text_filter($text),
			'CreateTime'=>time(),
			'FuncFlag'=>$FuncFlag
		);
		$this->Message($msg);
		return $this;
	}
	/*
	* 回复 客服消息  需要有authorizer_access_token
	*/
	public function send_custom_text($text){
		if( !$this->_authorizer_access_token && !$this->checkAuth() ) return false;
		$data_to_send = json_encode( array(
				'touser'=>$this->getRevFrom(),
				'msgtype'=>'text',
				'text'=>array('content'=>$text),
			) );
		$request_url = API_URL_PREFIX.CUSTOM_SEND_URL.'access_token='.$this->_authorizer_access_token;
		$ret = $this->http_post($request_url,$data_to_send);
		if( $ret ){
			$json = json_decode( $ret ,true);
			if( !$json || !empty( $json['errcode'] ) ){
				$this->errCode = $json['errcode'];
				$this->errMsg = $json['errmsg'];
				return false;
			}
			return $json;
		}
		return false;
	}

	/**
	 *
	 * 回复微信服务器, 此函数支持链式操作
	 * Example: $this->text('msg tips')->reply();
	 * @param string $msg 要发送的信息, 默认取$this->_msg
	 * @param bool $return 是否返回信息而不抛出到浏览器 默认:否
	 */
	public function reply($msg=array(),$return = false)
	{
		if (empty($msg))
			$msg = $this->_msg_to_send;
		$xmldata=  $this->xml_encode($msg);
		$this->log($xmldata);
		$this->encryptSendMsg($xmldata);
		if ($return)
			return $this->encryptMsg;
		else
			echo $this->encryptMsg;
	}

	/**
	* 把要发送的数据 传给结构
	*/
	private function Message($msg = ''){
		if( is_null( $msg ) ){
			$this->_msg_to_send = array();
		}else if( is_array( $msg ) ){
			$this->_msg_to_send = $msg;
			return $this->_msg_to_send;
		}else{
			return $this->_msg_to_send;
		}
	}

	

	/**
	 * GET 请求
	 * @param string $url
	 */
	private function http_get($url){
		$oCurl = curl_init();
		if(stripos($url,"https://")!==FALSE){
			curl_setopt($oCurl, CURLOPT_SSL_VERIFYPEER, FALSE);
			curl_setopt($oCurl, CURLOPT_SSL_VERIFYHOST, FALSE);
		}
		curl_setopt($oCurl, CURLOPT_URL, $url);
		curl_setopt($oCurl, CURLOPT_RETURNTRANSFER, 1 );
		$sContent = curl_exec($oCurl);
		$aStatus = curl_getinfo($oCurl);
		curl_close($oCurl);
		if(intval($aStatus["http_code"])==200){
			return $sContent;
		}else{
			return false;
		}
	}

	/**
	 * POST 请求
	 * @param string $url
	 * @param array $param
	 * @return string content
	 */
	private function http_post($url,$param){
		$oCurl = curl_init();
		if(stripos($url,"https://")!==FALSE){
			curl_setopt($oCurl, CURLOPT_SSL_VERIFYPEER, FALSE);
			curl_setopt($oCurl, CURLOPT_SSL_VERIFYHOST, false);
		}
		if (is_string($param)) {
			$strPOST = $param;
		} else {
			$aPOST = array();
			foreach($param as $key=>$val){
				$aPOST[] = $key."=".urlencode($val);
			}
			$strPOST =  join("&", $aPOST);
		}
		curl_setopt($oCurl, CURLOPT_URL, $url);
		curl_setopt($oCurl, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt($oCurl, CURLOPT_POST,true);
		curl_setopt($oCurl, CURLOPT_POSTFIELDS,$strPOST);
		$sContent = curl_exec($oCurl);
		$aStatus = curl_getinfo($oCurl);
		curl_close($oCurl);
		if(intval($aStatus["http_code"])==200){
			return $sContent;
		}else{
			return false;
		}
	}
}

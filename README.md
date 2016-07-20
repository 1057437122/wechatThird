# wechatThird/php sdk
@author:libaishun007@gmail.com
微信开放平台php sdk
网上很多微信公众平台的sdk，但是开放平台的不多，正好工作时用到了，就整理一份~
使用方法：
1、首先把需要的参数 传递进来
  $info = array('appid'=>'xxxxx','appsecret'=>'xxxxx','component_verify_ticket'=>'xxxxx');
  $info['debug'] = true;//是否调试
  $info['logcallback'] = 'trace';//写日志的回调函数，使用者可以自己定义，如TP中的trace
  $info['cachedcallback'] = array($this,'getCacheParam');//读取缓存数据的方法，与微信通讯的许多token都需要缓存的，所以这个方法建议使用者一定自己定义，函数格式为function getCacheParam('component_access_token')即为读取缓存的component_access_token的方法，注意，这个自己定义的方法必须判断数据是否过期，若过期必须返回false
  $info['cachecallback']  = array($this,'cacheParam');//与上面类似，这个是缓存数据的方法，缓存的格式是function cacheParam(array('item'=>'component_access_token','data'=>array('expires_in'=>7200,'component_access_token'=>'xxxxx',)))，缓存时注意收到数据的时间
  $info['authorizer_appid']= isset($_GET['appid']) ? $_GET['appid'] : '';//微信平台向使用者定义的公众号消息与事件接收URL发送数据时，会将$appid传过来，授权事件不需要此参数
  $info['authorizer_refresh_token'] ='';//如果已经授权完成，那么此参数必填，否则无法刷新 authorizer_access_token，授权事件不需要此参数
2、接着实例化这个类
$wechatObj = new WechatThird($info);
3、
1>若是授权事件接收的方法则：
$InfoType = $wechatObj->getRev()->getRevAuthInfoType();
switch( $InfoType ){
			case WechatThird::INFOTYPE_AUTHORIZED:
			//授权事件
			/*codes*/
			case WechatThird::INFOTYPE_UPDATEAUTHORIZED:
			//更新授权事件
			/*codes*/
			case WechatThird::INFOTYPE_UNAUTHORIZED:
			//取消授权
			/*codes*/
	}
2>若是推送公众号的消息事件接收方法，则可以根据收到的消息类型进行处理
$MsgType = $wechatObj->getRev()->getRevType();
		switch( $MsgType ){
			case WechatThird::MSGTYPE_EVENT:
			//事件类型
				$event = $wechatObj->getRev()->getRevEvent();
				$data = array(
					'wechat_origin_id' 	=> $wechatObj->getRevFrom(),
					'open_id'			=> $wechatObj->getRevTo(),
				);
				switch ( $event ){
					case WechatThird::EVENT_UNSUBSCRIBE:
					/*codes*/
					case ...
				}
			case WechatThird::MSGTYPE_TEXT:
			//文本类型
			/*codes*/
			
		}

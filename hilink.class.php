<?php

/**
 * hilink.class.php
 *
 * @author Andreas Mueller <webmaster@am-wd.de>
 * @version 1.0-20140703
 *
 * @description
 * This class tries to fully control an UMTS Stick from Huawei
 * with an HiLink Webinterface
 *
 * functionality tested with Huawei E303
 * Link: http://www.huaweidevices.de/e303
 **/
/*
This class was last tested and modified for:

E303
hardware: CH1E3531SMd
software: 22.318.25.00.414
web ui: 15.100.10.00.414

E8372
hardware: CL1E8372HM
software: 21.316.01.04.274
web ui: 17.100.45.10.274
*/

//Thanks to:
//https://github.com/if0xx/Huawei-Hilink-API

namespace AMWD;

@error_reporting(0);

/* ---                     DEPENDENCIES                           ---
------------------------------------------------------------------ */
function_exists('curl_version') or die('cURL Extension needed'.PHP_EOL);
function_exists('simplexml_load_string') or die('simplexml needed'.PHP_EOL);

/* ---                     CONSTANTS                           ---
------------------------------------------------------------------ */
define("ERROR_SYSTEM_NO_SUPPORT", 100002);
define("ERROR_SYSTEM_NO_RIGHTS",100003);
define("ERROR_SYSTEM_BUSY",100004);
define("ERROR_FORMAT_ERROR",100005);
define("ERROR_LOGIN_USERNAME_WRONG",108001);
define("ERROR_LOGIN_PASSWORD_WRONG",108002);
define("ERROR_LOGIN_ALREADY_LOGIN",108003);
define("ERROR_LOGIN_USERNAME_PWD_WRONG",108006);
define("ERROR_LOGIN_USERNAME_PWD_ORERRUN",108007);
define("ERROR_VOICE_BUSY",120001);
define("ERROR_WRONG_TOKEN",125001);
define("ERROR_LOGIN", 125002); //if not correctly logged in...
define("SMS_SYSTEMBUSY", 113018);

define ("SMS_BOX_IN", 1);
define ("SMS_BOX_OUT", 2);
define ("SMS_BOX_DRAFT", 3);
define ("SMS_BOX_DELETED", 4);
define ("SMS_BOX_UNREAD", "unread");

define ("CONNECTION_STATUS_CONNECTING", 900);
define ("CONNECTION_STATUS_CONNECTED", 901);
define ("CONNECTION_STATUS_DISCONNECTED", 902);
define ("CONNECTION_STATUS_DISCONNECTING", 903);

define ("API_VERSION_E303", 1);
define ("API_VERSION_E8372", 2);

class HiLink {
	// Class Attributes
	protected $host;  //the hilink IP
    protected $ipcheck; //required only for getExternalIp(), an URL that only returns your external IP as result
    protected $useToken; //if true __RequestVerificationToken will be added to the request for older api versions this may not be needed
    protected $common_headers; //common headers that will be added to each request
    protected $sessionID; //array of cookies and values
    protected $requestTokenOne; //tokens are needed for modems that require authentication like the E8372
    protected $requestTokenTwo;
	protected $requestToken;
    protected $api_version;
    
	public $trafficStats, $monitor, $device, $lastError, $status_xml;

	/**
	 * HiLink::__construct()
	 * 
	 * @param string $host the host IP of the huawei stick
	 * @param mixed $external_ip_check http url(s) which return the public IP address, can be array for backup in case a link doesn't work anymore
	 * @param integer $timeout optional a timeout for communicating with the stick
	 * @return void
	 */
	public function __construct($host='192.168.8.1', $external_ip_check= null, $timeout=60, $version=API_VERSION_E303) {
	   $this->common_headers=array();
	    if ($external_ip_check==null){
	       $external_ip_check=array("http://icanhazip.com/","http://checkip.amazonaws.com/","https://wtfismyip.com/text", "http://ipecho.net/plain","http://v4.ident.me/", "http://smart-ip.net/myip");
        }
        if ($version==API_VERSION_E8372){
            $this->common_headers[]="X-Requested-With: XMLHttpRequest";
        }
	    $this->setHost($host);
		$this->setIpCheck($external_ip_check);
        $this->useToken=true;
        $this->timeout=$timeout;
        $this->api_version = $version;
        $this->tokens=array();
	}

	// call default constructor
	public static function create() {
		return new self();
	}

	// call constructor and set url to HiLink
	public static function host($url) {
		$self = new self();
		$self->setHost($url);
		return $self;
	}

	// url to HiLik -> host
	public function setHost($host) {
		if (substr($host,0,5) == 'https') {
			$this->host = str_replace('/', '', substr($host,0,6));
		} else if (substr($host,0,4) == 'http') {
			$this->host = str_replace('/', '', substr($host,0.5));
		} else {
			$this->host = $host;
		}
	}

	public function getHost() {
		return $this->host;
	}

	// check if server (HiLink host) is reachable
	/**
	 * HiLink::online()
	 * Checks if the $server is reachable 
	 * @param string $server optional or will use the one from setHost
	 * @param integer $timeout
     * @param string $status , returns the status xml 
	 * @return bool
	 */
	public function online($server = '', $timeout = 2) {
		if (empty($server))
				$server = $this->host;
        
		$sys = $this->getSystem();
		switch ($sys) {
			case "win":
				$cmd = "ping -n 1 -w ".($timeout * 100)." ".$server;
				break;
			case "mac":
			$cmd = "ping -c 1 -t ".$timeout." ".$server." 2> /dev/null";
			break;
			case "lnx":
				$cmd = "ping -c 1 -W ".$timeout." ".$server." 2> /dev/null";
				break;
			default:
				return false;
		}

		$res = exec($cmd, $out, $ret);

		if ($ret == 0) {
			/*$ch = $this->init_curl('http://'.$server.'/api/monitoring/status', null, false);
			$rt = curl_exec($ch);
            $this->status_xml = $rt;
			curl_close($ch);
            $res = simplexml_load_string($rt);*/
			return $this->getMonitor()!==false; //!$this->isError($res) && $res->classify=='hilink';  //(strstr($res, 'response')) ? true : false;
		}

		return false;
	}


	// url to check external ip
	public function setIpCheck($url) {
		$this->ipcheck = $url;
	}
	public function getIpCheck() {
		return $this->ipcheck;
	}

	// returns the external ip address
	public function getExternalIp() {
	   $sc = stream_context_create(array('http' => array('timeout' => $this->timeout)));
	   if (is_array($this->ipcheck)){
	        $i=0;
	        $ip='';
            while ($i < count($this->ipcheck) && $ip==''){
                $ip = filter_var(@file_get_contents($this->ipcheck[$i], false, $sc), FILTER_VALIDATE_IP);
                $i++;
            }
            return $ip;
        }else{
    		$ip = filter_var(@file_get_contents($this->ipcheck, false, $sc), FILTER_VALIDATE_IP);
    		return $ip;
       }
	}

	/* --- Traffic Statistics
	------------------------- */
	/**
	 * HiLink::getTrafficStatistic()
	 * Returns stats about traffic
	 * @return statistics object or false in case of an error
    * <CurrentConnectTime>0</CurrentConnectTime>
    <CurrentUpload>0</CurrentUpload>
    <CurrentDownload>0</CurrentDownload>
    <CurrentDownloadRate>0</CurrentDownloadRate>
    <CurrentUploadRate>0</CurrentUploadRate>
    <TotalUpload>0</TotalUpload>
    <TotalDownload>0</TotalDownload>
    <TotalConnectTime>41</TotalConnectTime>
    <showtraffic>1</showtraffic>
	 */
	public function getTrafficStatistic() {
		$stats = $this->trafficStats;

		if (isset($stats->UpdateTime) && ($stats->UpdateTime + 3) > time()) {
			return $stats;
		}

		$ch = $this->init_curl($this->host.'/api/monitoring/traffic-statistics', null, false);
		$res = curl_exec($ch);
		curl_close($ch);

		$stats = simplexml_load_string($res);
        if (!$this->isError($stats)){
    		$stats->UpdateTime = time();
    		$this->trafficStats = $stats;
    		return $stats;
        }
        return false;
	}

	// Online Time
	public function getOnlineTime() {
		$stats = $this->getTrafficStatistic();
		return $this->getTime($stats->CurrentConnectTime);
	}

	public function getTotalOnlineTime() {
		$stats = $this->getTrafficStatistic();
		return $this->getTime($stats->TotalConnectTime);
	}

	// Upload
	public function getTotalUpload() {
		$stats = $this->getTrafficStatistic();
		return $this->getData($stats->TotalUpload);
	}

	public function getCurrentUpload() {
		$stats = $this->getTrafficStatistic();
		return $this->getData($stats->CurrentUpload);
	}

	public function getUploadRate() {
		$stats = $this->getTrafficStatistic();
		return $this->getData($stats->CurrentUploadRate).'/s';
	}

	// Download
	public function getTotalDownload() {
		$stats = $this->getTrafficStatistic();
		return $this->getData($stats->TotalDownload);
	}

	public function getCurrentDownload() {
		$stats = $this->getTrafficStatistic();
		return $this->getData($stats->CurrentDownload);
	}

	public function getDownloadRate() {
		$stats = $this->getTrafficStatistic();
		return $this->getData($stats->CurrentDownloadRate).'/s';
	}

	// collected output
	public function getTraffic($asArray = false) {
		if ($asArray) {
			return array(
			"timeCurrent"     => $this->getOnlineTime(),
			"timeTotal"       => $this->getTotalOnlineTime(),
			"uploadTotal"     => $this->getTotalUpload(),
			"uploadCurrent"   => $this->getCurrentUpload(),
			"uploadRate"      => $this->getUploadRate(),
			"downloadTotal"   => $this->getTotalDownload(),
			"downloadCurrent" => $this->getCurrentDownload(),
			"downloadRate"    => $this->getDownloadRate()
			);
		} else {
			$ret = '';
			// current
			$ret .= "Current Session:".PHP_EOL;
			$ret .= "- Time:     ".$this->getOnlineTime().PHP_EOL;
			$ret .= "- Upload:   ".$this->getCurrentUpload();
			$ret .= " [".$this->getUploadRate()."]";
			$ret .= PHP_EOL;
			$ret .= "- Download: ".$this->getCurrentDownload();
			$ret .= " [".$this->getDownloadRate()."]";
			$ret .= PHP_EOL;
			// total
			$ret .= "Total Data:".PHP_EOL;
			$ret .= "- Time:     ".$this->getTotalOnlineTime().PHP_EOL;
			$ret .= "- Upload:   ".$this->getTotalUpload().PHP_EOL;
			$ret .= "- Download: ".$this->getTotalDownload().PHP_EOL;
			return $ret;
		}
	}
    
	public function printTraffic() {
		echo $this->getTraffic();
	}

	/**
	 * HiLink::resetTrafficStats()
	 * Returns OK if traffic stats were reset
	 * @return
	 */
	public function resetTrafficStats() {
		$opts = array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_POST => 1,
			CURLOPT_POSTFIELDS => "<request><ClearTraffic>1</ClearTraffic></request>"
		);	   
		$ch = $this->init_curl($this->host.'/api/monitoring/clear-traffic', $opts);
		$ret = curl_exec($ch);
		curl_close($ch);

		$res = simplexml_load_string($ret);
		return $this->isOK($res);
	}

	/* --- Provider
	--------------- */
	/**
	 * HiLink::getProvider()
	 * Returns current provider
	 * @param string $length full for full name, short for short name, leave '' to return the xml object
	 * @return string or object(State>0</State>
                                <FullName>Mobistar</FullName>
                                <ShortName>Mobistar</ShortName>
                                <Numeric>20610</Numeric>
                                <Rat>2</Rat>)
	 */
	public function getProvider($length = 'full') {
		$ch = $this->init_curl($this->host.'/api/net/current-plmn', null, false);
		$ret = curl_exec($ch);
		curl_close($ch);
		$res = simplexml_load_string($ret);

        if (!$this->isError($res)){
    		switch ($length) {
    			case 'full': return ''.$res->FullName; break;
    			case 'short': return ''.$res->ShortName; break;
    			default: return $res;
    		}
        }
        return false;
	}

	/* --- Monitoring Stats
	----------------------- */

	/**
	 * HiLink::getMonitor()
	 * Returns status monitor object or false on error
	 * @return object(<ConnectionStatus>902</ConnectionStatus>
                        <WifiConnectionStatus></WifiConnectionStatus>
                        <SignalStrength></SignalStrength>
                        <SignalIcon>4</SignalIcon>
                        <CurrentNetworkType>41</CurrentNetworkType>
                        <CurrentServiceDomain>3</CurrentServiceDomain>
                        <RoamingStatus>1</RoamingStatus>
                        <BatteryStatus></BatteryStatus>
                        <BatteryLevel></BatteryLevel>
                        <BatteryPercent></BatteryPercent>
                        <simlockStatus>0</simlockStatus>
                        <WanIPAddress></WanIPAddress>
                        <WanIPv6Address></WanIPv6Address>
                        <PrimaryDns></PrimaryDns>
                        <SecondaryDns></SecondaryDns>
                        <PrimaryIPv6Dns></PrimaryIPv6Dns>
                        <SecondaryIPv6Dns></SecondaryIPv6Dns>
                        <CurrentWifiUser></CurrentWifiUser>
                        <TotalWifiUser></TotalWifiUser>
                        <currenttotalwifiuser>0</currenttotalwifiuser>
                        <ServiceStatus>2</ServiceStatus>
                        <SimStatus>1</SimStatus>
                        <WifiStatus></WifiStatus>
                        <CurrentNetworkTypeEx>41</CurrentNetworkTypeEx>
                        <maxsignal>5</maxsignal>
                        <wifiindooronly>-1</wifiindooronly>
                        <wififrequence>0</wififrequence>
                        <msisdn></msisdn>
                        <classify>hilink</classify>
                        <flymode>0</flymode>)
	 */
	public function getMonitor() {
		$monitor = $this->monitor;
		if (isset($monitor->UpdateTime) && ($monitor->UpdateTime + 3) > time()) {
			return $monitor;
		}

		$ch = $this->init_curl($this->host.'/api/monitoring/status', null, false);
		$res = curl_exec($ch);
        $this->status_xml = $res;
		curl_close($ch);

		$monitor = simplexml_load_string($res);
        if (!$this->isError($monitor)){
    		$monitor->UpdateTime = time();
    		$this->monitor = $monitor;
    		return $monitor;
        }
        return false;
	}

	// IP provider
	public function getProviderIp() {
		$mon = $this->getMonitor();
		return ''.$mon->WanIPAddress;
	}

	// get DNS server
	public function getDnsServer($server = 1) {
		$mon = $this->getMonitor();
		if ($server == 2) {
			return ''.$mon->SecondaryDns;
		} else {
			return ''.$mon->PrimaryDns;
		}
	}

	// connection status
	/**
	 * HiLink::getConnectionStatus()
	 * Returns current connection status
	 * @return string
	 */
	public function getConnectionStatus() {
		$mon = $this->getMonitor();
		switch ($mon->ConnectionStatus) {
			case "112": return "No autoconnect";
			case "113": return "No autoconnect (roaming)";
			case "114": return "No reconnect on timeout";
			case "115": return "No reconnect on timeout (roaming)";
			case "900": return "Connecting";
			case "901": return "Connected";
			case "902": return "Disconnected";
			case "903": return "Disconnecting";
			default: return "Unknown status";
		}
	}

	// connection type
	public function getConnectionType() {
		$mon = $this->getMonitor();
		switch ($mon->CurrentNetworkType) {
			case "3": return "2G";
			case "4": return "3G";
			case "7": return "3G+";
			default: return "Unknown type";
		}
	}

	/**
	 * HiLink::setConnectionType()
	 * Sets connection type
	 * @param string $type
	 * @param string $band
	 * @return bool
	 */
	public function setConnectionType($type = 'auto', $band = '-1599903692') {
		$type = strtolower($type);
		$req = new \SimpleXMLElement('<request></request>');
		switch ($type) {
			case '2g': $req->addChild('NetworkMode', 1); break;
			case '3g': $req->addChild('NetworkMode', 2); break;
			default:   $req->addChild('NetworkMode', 0); break;
		}
		$req->addChild('NetworkBand', $band);

		$opts = array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_POST => 1,
			CURLOPT_POSTFIELDS => $req->asXML(),
		);
		$ch = $this->init_curl($this->host.'/api/net/network', $opts);

		$ret = curl_exec($ch);
		curl_close($ch);

		$res = simplexml_load_string($ret);
		return $this->isOK($res);
	}

	// signal strength
	public function getSignalStrength() {
		$mon = $this->getMonitor();
		return $mon->SignalStrength.'%';
	}

	// SIM
	public function getSimStatus() {
		$mon = $this->getMonitor();
		if ($mon->SimStatus == 1) {
			return "SIM ok";
		} else {
			return "SIM fail";
		}
	}

	public function getServiceStatus() {
		$mon = $this->getMonitor();
		if ($mon->ServiceStatus == 2) {
			return "PIN ok";
		} else {
			return "enter PIN";
		}
	}

	public function getSystemStatus() {
		return $this->getSimStatus().' ['.$this->getServiceStatus().']';
	}

	// roaming
	public function getRoamingStatus() {
		$mon = $this->getMonitor();
		switch ($mon->RoamingStatus) {
			case 0: return "inactive";
			case 1: return "active";
			default: return "unknown";
		}
	}

	// collected output
	public function getStatus($asArray = false) {
		if ($asArray) {
			return array(
			"status"    => $this->getSystemStatus(),
			"roaming"   => $this->getRoamingStatus(),
			"conStatus" => $this->getConnectionStatus(),
			"conType"   => $this->getConnectionType(),
			"sigStr"    => $this->getSignalStrength(),
			"ipProv"    => $this->getProviderIp(),
			"ipExt"     => $this->getExternalIp(),
			"ipDNS1"    => $this->getDnsServer(),
			"ipDNS2"    => $this->getDnsServer(2)
			);
		} else {
			$out = "";
			$out .= "System-Status:       ".$this->getSystemStatus().PHP_EOL;
			$out .= "Roaming:             ".$this->getRoamingStatus().PHP_EOL;
			$out .= "Connection-)tatus:   ".$this->getConnectionStatus().PHP_EOL;
			$out .= "Connection-Type:     ".$this->getConnectionType().PHP_EOL;
			$out .= "Connection-Strength: ".$this->getSignalStrength().PHP_EOL;
			$out .= "IPv4 - Provider:     ".$this->getProviderIp().PHP_EOL;
			$out .= "IPv4 - external:     ".$this->getExternalIp().PHP_EOL;
			$out .= "IPv4 - DNS (1):      ".$this->getDnsServer().PHP_EOL;
			$out .= "IPv4 - DNS (2):      ".$this->getDnsServer(2).PHP_EOL;
			return $out;
		}
	}
	public function printStatus() {
		echo $this->getStatus();
	}

	/* --- PIN actions
	------------------ */
	/**
	 * HiLink::getPin()
	 * Returns pin status
	 * @return object (SimState->int, PinOptState->int,SimPinTimes->int,SimPukTimes->int) or false on error
	 */
	public function getPin() {
	   
		$ch = $this->init_curl($this->host.'/api/pin/status', null, false);
		$ret = curl_exec($ch);
		curl_close($ch);

		$res = simplexml_load_string($ret);
        if (!$this->isError($res)) return $res;
		return false;
	}

	private function pinDo($type, $pin, $new = '', $puk = '') {
		$req = new \SimpleXMLElement('<request></request>');
		$req->addChild('OperateType', $type);
		$req->addChild('CurrentPin', $pin);
		$req->addChild('NewPin', $new);
		$req->addChild('PukCode', $puk);

		$opts = array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_POST => 1,
			CURLOPT_POSTFIELDS => $req->asXML(),
		);
		$ch = $this->init_curl($this->host.'/api/pin/operate',$opts);

		$ret = curl_exec($ch);
		curl_close($ch);

		$res = simplexml_load_string($ret);
		return $this->isOK($res);
	}

	public function pinEnter($pin) {
		return $this->pinDo(0, $pin);
	}

	public function pinActivate($pin) {
		return $this->pinDo(1, $pin);
	}

	public function pinDeactivate($pin) {
		return $this->pinDo(2, $pin);
	}

	public function pinChange($pin, $new) {
		return $this->pinDo(3, $pin, $new);
	}

	public function pinEnterPuk($puk, $newPin) {
		return $this->pinDo(4, $newPin, $newPin, $puk);
	}

	public function getPinTryLeft() {
		$st = $this->getPin();
		return $st->SimPinTimes;
	}

	public function getPukTryLeft() {
		$st = $this->getPin();
		return $st->SimPukTimes;
	}

	public function getPinStatus($asArray = false) {
		$st = $this->getPin();
		if ($asArray) {
			return array(
				"pinTry" => $st->SimPinTimes,
				"pukTry" => $st->SimPukTimes,
			);
		} else {
			$out = '';
			$out .= 'PIN Tries Left: '.$st->SimPinTimes.PHP_EOL;
			$out .= 'PUK Tries Left: '.$st->SimPukTimes.PHP_EOL;
			return $out;
		}
	}

	public function printPinStatus() {
		echo $this->getPinStatus();
	}

	/* --- Connection
	----------------- */

    /**
     * HiLink::connectMDS()
     * Connects using another method than dialup (mobile-dataswitch API), it will probably depend on which firmware version / type of modem you have if you should use this or the regular connect method
     * @return bool
     */
    public function connectMDS(){
		$opts = array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_POST => 1,
			CURLOPT_POSTFIELDS => '<?xml version="1.0" encoding="UTF-8"?><request><dataswitch>1</dataswitch></request>');
		$ch = $this->init_curl($this->host.'/api/dialup/mobile-dataswitch', $opts);

		$ret = curl_exec($ch);
		curl_close($ch);

		$res = simplexml_load_string($ret);
        return $this->isOK($res);
    }

    /**
     * HiLink::disconnectMDS()
     * Disconnects the mobile dataswitch connection
     * @return bool
     */
    public function disconnectMDS(){
		$opts = array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_POST => 1,
			CURLOPT_POSTFIELDS => '<?xml version="1.0" encoding="UTF-8"?><request><dataswitch>0</dataswitch></request>');
		$ch = $this->init_curl($this->host.'/api/dialup/mobile-dataswitch', $opts);

		$ret = curl_exec($ch);
		curl_close($ch);

		$res = simplexml_load_string($ret);
        return $this->isOK($res);
        /*if (!$this->isError($res)){
            return intval($res->dataswitch)==0;   
        }*/
		return false;        
    }


	/**
	 * HiLink::connect()
	 * Connects to the 3G network using dialup
	 * @return bool
	 */
	public function connect() {
		if ($this->isConnected())
				return true;

		$opts = array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_POST => 1,
			CURLOPT_POSTFIELDS => "<request><Action>1</Action></request>");
		$ch = $this->init_curl($this->host.'/api/dialup/dial', $opts);

		$ret = curl_exec($ch);
		curl_close($ch);

		$res = simplexml_load_string($ret);

		return $this->isOK($res);
	}

    /**
     * HiLink::getconnectedMDS()
     * Returns if mobile dataswitch is on
     * @return bool
     */
    public function getconnectedMDS(){
        $ch = $this->init_curl($this->host.'/api/dialup/mobile-dataswitch', null, false);
        $ret = curl_exec($ch);
        curl_close($ch);
        $res =  simplexml_load_string($ret);
        if (!$this->isError($res)){
            return intval($res->dataswitch)==1;
        }
        return false;
    }

	/**
	 * HiLink::disconnect()
	 * Disconnects from the 3G dialup network
	 * @return bool
	 */
	public function disconnect() {
		if (!$this->isConnected())
				return true;

		$opts = array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_POST => 1,
			CURLOPT_POSTFIELDS => "<request><Action>0</Action></request>",
		);
		$ch = $this->init_curl($this->host.'/api/dialup/dial', $opts);

		$ret = curl_exec($ch);
		curl_close($ch);

		$res = simplexml_load_string($ret);

		return $this->isOK($res);
	}

	/**
	 * HiLink::isConnected()
	 * Returns if currently connected to the 3G network
	 * @return bool
	 */
	public function isConnected() {
		$st = $this->getConnectionStatus();
		return ($st == 'Connected');
	}

	/**
	 * HiLink::getConnection()
	 * Returns current connection info
	 * @param bool $asArray
	 * @return Array or false on error
	 */
	public function getConnection($asArray = false) {
		$ch = $this->init_curl($this->host.'/api/dialup/connection', null, false);

		$ret = curl_exec($ch);
		curl_close($ch);
		$res = simplexml_load_string($ret);
        if ($this->isError($res))return false;
		if ($asArray) {
			return array(
				"ConnectMode"          => $res->ConnectMode,
				"AutoReconnect"        => $res->AutoReconnect,
				"RoamingAutoConnect"   => $res->RoamAutoConnectEnable,
				"RoamingAutoReconnect" => $res->RoamAutoReconnctEnable,
				"ReconnectInterval"    => $res->ReconnectInterval,
				"MaxIdleTime"          => $res->MaxIdelTime,
			);
		} else {
			$out = '';
			$out .= "Auto Connect:           ".(($res->ConnectMode == 0) ? "1" : "0").PHP_EOL;
			$out .= "Auto Reconnect:         ".$res->AutoReconnect.PHP_EOL;
			$out .= "Roaming Auto Connect:   ".$res->RoamAutoConnectEnable.PHP_EOL;
			$out .= "Roaming Auto Reconnect: ".$res->RoamAutoReconnctEnable.PHP_EOL;
			$out .= "Reconnect Interval:     ".$res->ReconnectInterval.PHP_EOL;
			$out .= "Max. Idle Time:         ".$res->MaxIdelTime.PHP_EOL;
			return $out;
		}
	}
	public function printConnection() {
		echo $this->getConnection();
	}

	/**
	 * HiLink::doConnection()
	 * Connects to 3G
	 * @param mixed $autoconnect
	 * @param mixed $reconnect
	 * @param mixed $roamingauto
	 * @param mixed $roamingre
	 * @param integer $interval
	 * @param integer $idle
	 * @return bool
	 */
	private function doConnection($autoconnect, $reconnect, $roamingauto, $roamingre, $interval = 3, $idle = 0) {
		$req = new \SimpleXMLElement('<request></request>');
		$req->addChild('RoamAutoConnectEnable', $roamingauto);
		$req->addChild('AutoReconnect', $reconnect);
		$req->addChild('RoamAutoReconnctEnable', $roamingre);
		$req->addChild('ReconnectInterval', $interval);
		$req->addChild('MaxIdelTime', $idle);
		$req->addChild('ConnectMode', $autoconnect);
		$opts = array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_POST => 1,
			CURLOPT_POSTFIELDS => $req->asXML());
		$ch = $this->init_curl($this->host.'/api/dialup/connection', $opts);

		$ret = curl_exec($ch);
		curl_close($ch);

		$res = simplexml_load_string($ret);

		return $this->isOK($res);
	}

	public function activateAutoconnect() {
		$get = $this->getConnection(true);
		return $this->doConnection(0, $get['AutoReconnect'], $get['RoamingAutoConnect'], $get['RoamingAutoReconnect']);
	}
	public function deactivateAutoconnect() {
		$get = $this->getConnection(true);
		return $this->doConnection(1, $get['AutoReconnect'], $get['RoamingAutoConnect'], $get['RoamingAutoReconnect']);
	}

	public function activateAutoReconnect() {
		$get = $this->getConnection(true);
		return $this->doConnection($get['ConnectMode'], 1, $get['RoamingAutoConnect'], $get['RoamingAutoReconnect']);
	}
	public function deactivateAutoReconnect() {
		$get = $this->getConnection(true);
		return $this->doConnection($get['ConnectMode'], 0, $get['RoamingAutoConnect'], $get['RoamingAutoReconnect']);
	}

	public function activateRoamingAutoconnect() {
		$get = $this->getConnection(true);
		return $this->doConnection($get['ConnectMode'], $get['AutoReconnect'], 1, $get['RoamingAutoReconnect']);
	}
	public function deactivateRoamingAutoconnect() {
		$get = $this->getConnection(true);
		return $this->doConnection($get['ConnectMode'], $get['AutoReconnect'], 0, $get['RoamingAutoReconnect']);
	}

	public function activateRoamingAutoReconnect() {
		$get = $this->getConnection(true);
		return $this->doConnection($get['ConnectMode'], $get['AutoReconnect'], $get['RoamingAutoConnect'], 1);
	}

	public function deactivateRoamingAutoReconnect() {
		$get = $this->getConnection(true);
		return $this->doConnection($get['ConnectMode'], $get['AutoReconnect'], $get['RoamingAutoConnect'], 0);
	}

	public function setReconnectInterval($seconds) {
		$get = $this->getConnection(true);
		return $this->doConnection($get['ConnectMode'], $get['AutoReconnect'], $get['RoamingAutoConnect'], $get['RoamingAutoReconnect'], $seconds);
	}

	/* --- Device Infos
	------------------- */
	public function getDevice() {
		$device = $this->device;
		if (isset($device->UpdateTime) && ($device->UpdateTime + 3) > time()) {
			return $device;
		}

		$ch = $this->init_curl($this->host.'/api/device/information');
		$res = curl_exec($ch);
		curl_close($ch);

		$device = simplexml_load_string($res);
        if (!$this->isError($device)){
    		$device->UpdateTime = time();
    		$this->device = $device;
    		return $device;
        }
        return false;
	}

	public function getDeviceName() {
		$dev = $this->getDevice();
		return ''.$dev->DeviceName;
	}

	public function getSerialNumber() {
		$dev = $this->getDevice();
		return ''.$dev->SerialNumber;
	}

	public function getIMEI() {
		$dev = $this->getDevice();
		return ''.$dev->Imei;
	}

	public function getIMSI() {
		$dev = $this->device;
		return ''.$dev->Imsi;
	}

	public function getICCID() {
		$dev = $this->getDevice();
		return ''.$dev->Iccid;
	}

	public function getPhoneNumber() {
		$dev = $this->getDevice();
		return ''.$dev->Msisdn;
	}

	public function getHardwareVersion() {
		$dev = $this->getDevice();
		return ''.$dev->HardwareVersion;
	}

	public function getSoftwareVersion() {
		$dev = $this->getDevice();
		return ''.$dev->SoftwareVersion;
	}

	public function getGuiVersion() {
		$dev = $this->getDevice();
		return ''.$dev->WebUIVersion;
	}

	public function getUptime() {
		$dev = $this->getDevice();
		return $this->getTime($dev->Uptime);
	}

	public function getMAC($interface = 1) {
		$dev = $this->getDevice();
		if ($interface == 2) {
			return ''.$dev->MacAddress2;
		} else {
			return ''.$dev->MacAddress1;
		}
	}

	public function getDeviceInfo($asArray = false) {
		if ($asArray) {
			return array(
				"name"   => $this->getDeviceName(),
				"sn"     => $this->getSerialNumber(),
				"imei"   => $this->getIMEI(),
				"imsi"   => $this->getIMSI(),
				"iccid"  => $this->getICCID(),
				"number" => $this->getPhoneNumber(),
				"hw"     => $this->getHardwareVersion(),
				"sw"     => $this->getSoftwareVersion(),
				"ui"     => $this->getGuiVersion(),
				"uptime" => $this->getUptime(),
				"mac"    => $this->getMAC(),
			);
		} else {
			$out = "";
			$out .= "Name:         ".$this->getDeviceName().PHP_EOL;
			$out .= "SerialNo:     ".$this->getSerialNumber().PHP_EOL;
			$out .= "IMEI:         ".$this->getIMEI().PHP_EOL;
			$out .= "IMSI:         ".$this->getIMSI().PHP_EOL;
			$out .= "ICCID:        ".$this->getICCID().PHP_EOL;
			$out .= "Phone Number: ".$this->getPhoneNumber().PHP_EOL;
			$out .= "HW Version:   ".$this->getHardwareVersion().PHP_EOL;
			$out .= "SW Version:   ".$this->getSoftwareVersion().PHP_EOL;
			$out .= "UI Version:   ".$this->getGuiVersion().PHP_EOL;
			$out .= "Uptime:       ".$this->getUptime().PHP_EOL;
			$out .= "MAC:          ".$this->getMAC().PHP_EOL;
			return $out;
		}
	}

	public function printDeviceInfo() {
		echo $this->getDeviceInfo();
	}

	/* --- APN
	---------- */

	/**
	 * HiLink::getApn()
	 * Returns the APN or false in case of an error
	 * @return
	 */
	public function getApn() {
		$ch = $this->init_curl($this->host.'/api/dialup/profiles');
		$ret = curl_exec($ch);
		curl_close($ch);
        $res = simplexml_load_string($ret);
        if (!$this->isError($res)) return $res;
        return false;
	}

	/**
	 * HiLink::createProfile()
	 * Creates a dialup profile
	 * @param mixed $name
	 * @param mixed $apn
	 * @param mixed $user
	 * @param mixed $password
	 * @param integer $isValid
	 * @param integer $apnIsStatic
	 * @param string $dailupNum
	 * @param integer $authMode
	 * @param integer $ipIsStatic
	 * @param string $ipAddress
	 * @param string $dnsIsStatic
	 * @param string $primaryDns
	 * @param string $secondaryDns
	 * @return bool true on success
	 */
	public function createProfile($name, $apn, $user, $password,
			$isValid = 1, $apnIsStatic = 1, $dailupNum = '*99#', $authMode = 0,
			$ipIsStatic = 0, $ipAddress = '0.0.0.0', $dnsIsStatic = '', $primaryDns = '', $secondaryDns = '') {
		$resq = new \SimpleXMLElement('<request></request>');
		$req->addChild('Delete', 0);
		$req->addChild('SetDefault', 0);
		$req->addChild('Modify', 1);
		$p = $req->addChild('Profile');
		$p->addChild('Index');
		$p->addChild('IsValid', $isValid);
		$p->addChild('Name', $name);
		$p->addChild('ApnIsStatic', $apnIsStatic);
		$p->addChild('ApnName', $apn);
		$p->addChild('DailupNum', $dailupNum);
		$p->addChild('Username', $user);
		$p->addChild('Password', $password);
		$p->addChild('AuthMode', $authMode);
		$p->addChild('IpIsStatic', $ipIsStatic);
		$p->addChild('IpAddress', $ipAddress);
		$p->addChild('DnsIsStatic', $dnsIsStatic);
		$p->addChild('PrimaryDns', $primarayDns);
		$p->addChild('SecondaryDns', $secondaryDns);
		$p->addChild('ReadOnly', 0);

		$opts = array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_POST => 1,
			CURLOPT_POSTFIELDS => $req->asXML(),
		);
		$ch = $this->init_curl($this->host.'/api/dialup/profiles', $opts);

		$ret = curl_exec($ch);
		curl_close($ch);

		$res = simplexml_load_string($ret);
		return $this->isOK($res);
	}

	/**
	 * HiLink::editProfile()
	 * Edits a dialup profile
	 * @param mixed $idx
	 * @param mixed $name
	 * @param mixed $apn
	 * @param mixed $user
	 * @param mixed $password
	 * @param integer $readOnly
	 * @param integer $isValid
	 * @param integer $apnIsStatic
	 * @param string $dailupNum
	 * @param integer $authMode
	 * @param integer $ipIsStatic
	 * @param string $ipAddress
	 * @param string $dnsIsStatic
	 * @param string $primaryDns
	 * @param string $secondaryDns
	 * @return bool
	 */
	public function editProfile($idx, $name, $apn, $user, $password,
			$readOnly = 0, $isValid = 1, $apnIsStatic = 1, $dailupNum = '*99#', $authMode = 0,
			$ipIsStatic = 0, $ipAddress = '0.0.0.0', $dnsIsStatic = '', $primaryDns = '', $secondaryDns = '') {
		$req = new \SimpleXMLElement('<request></request>');
		$req->addChild('Delete', 0);
		$req->addChild('SetDefault', $idx);
		$req->addChild('Modify', 2);
		$p = $req->addChild('Profile');
		$p->addChild('Index', $idx);
		$p->addChild('IsValid', $isValid);
		$p->addChild('Name', $name);
		$p->addChild('ApnIsStatic', $apnIsStatic);
		$p->addChild('ApnName', $apn);
		$p->addChild('DailupNum', $dailupNum);
		$p->addChild('Username', $user);
		$p->addChild('Password', $password);
		$p->addChild('AuthMode', $authMode);
		$p->addChild('IpIsStatic', $ipIsStatic);
		$p->addChild('IpAddress', $ipAddress);
		$p->addChild('DnsIsStatic', $dnsIsStatic);
		$p->addChild('PrimaryDns', $primarayDns);
		$p->addChild('SecondaryDns', $secondaryDns);
		$p->addChild('ReadOnly', $readOnly);

		$opts = array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_POST => 1,
			CURLOPT_POSTFIELDS => $req->asXML(),
		);
		$ch = $this->init_curl($this->host.'/api/dialup/profiles', $opts);

		$ret = curl_exec($ch);
		curl_close($ch);

		$res = simplexml_load_string($ret);
		return $this->isOK($res);
	}

	/**
	 * HiLink::setProfileDefault()
	 * Sets default profile index
	 * @param int $idx
	 * @return bool
	 */
	public function setProfileDefault($idx) {
		$req = new \SimpleXMLElement('<request></request>');
		$req->addChild('Delete', 0);
		$req->addChild('SetDefault', $idx);
		$req->addChild('Modify', 0);

		$opts = array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_POST => 1,
			CURLOPT_POSTFIELDS => $req->asXML(),
		);
		$ch = $this->init_curl($this->host.'/api/dialup/profiles', $opts);

		$ret = curl_exec($ch);
		curl_close($ch);

		$res = simplexml_load_string($ret);
		return $this->isOK($res);
	}

	/**
	 * HiLink::deleteProfile()
	 * Deletes a profile
	 * @param int $idx
	 * @return bool
	 */
	public function deleteProfile($idx) {
		$req = new \SimpleXMLElement('<request></request>');
		$req->addChild('Delete', $idx);
		$req->addChild('SetDefault', 1);
		$req->addChild('Modify', 0);

		$opts = array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_POST => 1,
			CURLOPT_POSTFIELDS => $req->asXML());
		$ch = $this->init_curl($this->host.'/api/dialup/profiles', $opts);

		$ret = curl_exec($ch);
		curl_close($ch);

		$res = simplexml_load_string($ret);
		return $this->isOK($res);
	}

	public function listApn($asArray = false) {
		$apn = $this->getApn();
		$p = $apn->Profiles->Profile;
		$res = array();

		for ($i = 0; $i < count($p); ++$i) {
			$ar = array();
			$ar['idx']  = ''.$p[$i]->Index;
			$ar['name'] = ''.$p[$i]->Name;
			$ar['apn']  = ''.$p[$i]->ApnName;
			$ar['user'] = ''.$p[$i]->Username;
			$ar['pass'] = ''.$p[$i]->Password;

			$res[] = $ar;
		}

		if ($asArray)
				return $res;

		$out = 'Index: Name [APN] - User:Password'.PHP_EOL;
		$out.= '---------------------------------'.PHP_EOL;
		foreach ($res as $line) {
			$out .= $line['idx'].': '.$line['name'].' ['.$line['apn'].'] - '.$line['user'].':'.$line['pass'].PHP_EOL;
		}
		return $out;
	}

	public function printApn() {
		echo $this->listApn();
	}

	/* --- SMS
	---------- */
	/**
	 * HiLink::getSms()
	 * 
	 * @param integer $box one of the SMS_BOX constants
	 * @param integer $site
	 * @param integer $prefUnread
	 * @param integer $count
	 * @return SimpleXMLElement object
	 */
	public function getSms($box = 1, $site = 1, $prefUnread = 0, $count = 20) {
		$req = new \SimpleXMLElement('<request></request>');
		$req->addChild('PageIndex', $site);
		$req->addChild('ReadCount', $count);
		$req->addChild('BoxType', $box);
		$req->addChild('SortType', 0);
		$req->addChild('Ascending', 0);
		$req->addChild('UnreadPreferred', $prefUnread);

		$opts = array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_POST => 1,
			CURLOPT_POSTFIELDS => $req->asXML());

		$ch = $this->init_curl($this->host.'/api/sms/sms-list', $opts);

		$ret = curl_exec($ch);
		curl_close($ch);
        $res = simplexml_load_string($ret);
        if (!$this->isError($res)){
            return $res;
        }else{
            return false;
        }
	}

	/**
	 * HiLink::doSmsBox()
	 * Loads sms messages from a certain box as an array
	 * @param mixed $box
	 * @param mixed $asArray
	 * @return Array or bool false on error
	 */
	private function doSmsBox($box, $asArray) {
		$box = $this->getSms($box);
        if ($box===false) return false;
		$ret = array();

		$msg = $box->Messages->Message;

		for ($i = 0; $i < $box->Count; ++$i) {
			$m = $msg[$i];
			$ar = array();
			$ar['idx'] = "$m->Index";
			$ar['read'] = (($m->Smstat == 1) ? true : false);
			$ar['number'] = "$m->Phone";
			$ar['msg'] = "$m->Content";
			$ar['time'] = strtotime($m->Date);
			$ar['proxy'] = "$m->Sca";

			$ret[] = $ar;
		}

		if ($asArray)
				return $ret;

		$out = '';
		foreach ($ret as $l) {
			$out .= $l['idx'].") ".$l['number']." - ".date('d.m.Y H:i:s').PHP_EOL;
			$out .= "Read: ".$l['read'].PHP_EOL;
			$out .= "Nachricht: ".$l['msg'].PHP_EOL;
		}

		return $out;
	}


	public function getSmsInbox($asArray = true) {
		return $this->doSmsBox(1, $asArray);
	}

	public function getSmsOutbox($asArray = true) {
		return $this->doSmsBox(2, $asArray);
	}

	public function getSmsDraft($asArray = true) {
		return $this->doSmsBox(3, $asArray);
	}

	public function printSmsBox($box = 0) {
		switch ($box) {
			case 1: echo $this->getSmsInbox(false); break;
			case 2: echo $this->getSmsOutbox(false); break;
			case 3: echo $this->getSmsDraft(false); break;
			default:
				echo "Inbox:".PHP_EOL;
				echo $this->getSmsInbox(false).PHP_EOL;
				echo "Outbox:".PHP_EOL;
				echo $this->getSmsOutbox(false).PHP_EOL;
				echo "Draft:".PHP_EOL;
				echo $this->getSmsDraft(false);
				break;
		}
	}

	/**
	 * HiLink::getNotifications()
	 * Returns notification messages
	 * @return object (UnreadMessage->int,SmsStorageFull->bool,OnlineUpdateStatus->int) or bool false on error
	 */
	public function getNotifications() {
		$ch = $this->init_curl($this->host.'/api/monitoring/check-notifications', null, false);
		$ret = curl_exec($ch);
		curl_close($ch);
        $res = simplexml_load_string($ret);
		if (!$this->isError($res)) return $res;
        return false;
	}

	/**
	 * HiLink::getUnreadSms()
	 * Returns number of new unread sms messages
	 * @return int or false on error
	 */
	public function getUnreadSms() {
		$not = $this->getNotifications();
		return $not->UnreadMessage;
	}

	/**
	 * HiLink::smsStorageFull()
	 * Returns if sms storage is full
	 * @return bool
	 */
	public function smsStorageFull() {
		$not = $this->getNotificaitons();
		return ($not->SmsStorageFull == 0) ? false : true;
	}

	/**
	 * HiLink::getSmsCount()
	 * Returns number of messages in $box
	 * @param string $box of: SMS_BOX_IN, SMS_BOX_OUT, SMS_BOX_DRAFT, SMS_BOX_DELETED, SMS_BOX_UNREAD
	 * @return int or false on error
	 */
	public function getSmsCount($box = 'default') {
		$ch = $this->init_curl($this->host.'/api/sms/sms-count', null, false);
		$ret = curl_exec($ch);
		curl_close($ch);

		$res = simplexml_load_string($ret);
        if (isError($res)) return false;
		switch ($box) {
			case 'in': 
            case 'inbox': 
            case 1:
            case SMS_BOX_IN:
				return "".$res->LocalInbox;
			case 'out': 
            case 'outbox': 
            case 2:
            case SMS_BOX_OUT:
				return "".$res->LocalOutbox;
			case 'draft': 
            case 'drafts': 
            case 3:
            case SMS_BOX_DRAFT:
				return "".$res->LocalDraft;
			case 'deleted': 
            case 4:
            case SMS_BOX_DELETED:
				return "".$res->localDeleted;
			case 'unread': 
            case 'new':
            case SMS_BOX_UNREAD:
				return "".$res->LocalUnread;
			default:
				return array(
					'inbox'   => "".$res->LocalInbox,
					'outbox'  => "".$res->LocalOutbox,
					'draft'   => "".$res->LocalDraft,
					'deleted' => "".$res->LocalDeleted,
					'unread'  => "".$res->LocalUnread,
				);
		}
	}

	/**
	 * HiLink::listUnreadSms()
	 * Lists all unread messages
	 * @return array of sms or false on error
	 */
	public function listUnreadSms() {
		$list = $this->getSmsInbox();
        if ($list===false) return false;
		$ret = array();
		foreach ($list as $sms) {
			if (!$sms['read']) {
				$ret[] = $sms;
			}
		}

		return $ret;
	}

	/**
	 * HiLink::setSmsRead()
	 * Changes the status of the SMS at $idx to read
	 * @param int $idx
	 * @return bool
	 */
	public function setSmsRead($idx) {
		$req = new \SimpleXMLElement('<request></request>');

		if (is_array($idx)) {
			for ($i = 0; $i < count($idx); $i++)
					$req->addChild('Index', $idx[$i]);
		} else {
			$req->addChild('Index', $idx);
		}

		$opts = array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_POST => 1,
			CURLOPT_POSTFIELDS => $req->asXML());
		$ch = $this->init_curl($this->host.'/api/sms/set-read',$opts);
		$ret = curl_exec($ch);
		curl_close($ch);

		$res = simplexml_load_string($ret);

		return $this->isOK($res);
	}

	/**
	 * HiLink::deleteSms()
	 * Delete SMS at memory index $idx
	 * @param int $idx
	 * @return bool
	 */
	public function deleteSms($idx) {
		$req = new \SimpleXMLElement('<request></request>');

		if (is_array($idx)) {
			for ($i = 0; $i < count($idx); $i++)
					$req->addChild('Index', $idx[$i]);
		} else {
			$req->addChild('Index', $idx);
		}

		$opts = array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_POST => 1,
			CURLOPT_POSTFIELDS => $req->asXML());
		$ch = $this->init_curl($this->host.'/api/sms/delete-sms', $opts);

		$ret = curl_exec($ch);
		curl_close($ch);

		$res = simplexml_load_string($ret);

		return $this->isOK($res);
	}

	/**
	 * HiLink::sendSms()
	 * Sends an SMS to $no with $message
	 * @param mixed $no
	 * @param string $message
	 * @param integer $idx
	 * @return bool true in case of no errors, use sendSMSStatus to check for sending status
	 */
	public function sendSms($no, $message, $idx = -1) {
		$req = new \SimpleXMLElement('<request></request>');
		$req->addChild('Index', $idx);
		$ph = $req->addChild('Phones');

		if (is_array($no)) {
			for ($i = 0; $i < count($no); $i++) {
				$ph->addChild('Phone', $no[$i]);
			}
		} else {
			$ph->addChild('Phone', $no);
		}

		$req->addChild('Sca');
		$req->addChild('Content', $message);
		$req->addChild('Length', strlen($message));
		$req->addChild('Reserved', 1);
		$req->addChild('Date', date('Y-m-d H:i:s'));

		$opts = array(
			CURLOPT_POST => 1,
			CURLOPT_POSTFIELDS => $req->asXML());
		$ch = $this->init_curl($this->host.'/api/sms/send-sms', $opts);

		//curl_setopt_array($ch, $opts);
		$ret = curl_exec($ch);
		curl_close($ch);
		$res = simplexml_load_string($ret);
        return $this->isOK($res);
	}

	/**
	 * HiLink::sendSmsStatus()
	 * object {TotalCount,
               CurIndex,
               Phone,
               SucPhone => Array(),
               FailPhone => Array()
              }
	 * @return SimpleXMLElement or false in case of an error returns false
	 */
	public function sendSmsStatus() {
		$ch = $this->init_curl($this->host.'/api/sms/send-status', null, false);
		$ret = curl_exec($ch);
		curl_close($ch);

		$res = simplexml_load_string($ret);
        if (!$this->isError($res)) return $res;
		return false;
	}

    /**
     * HiLink::getToken()
     * Gets a new token for posting or returns empty string on error
     * @return string
     */
    public function getToken(){
        $ch = curl_init($this->host.'/api/webserver/token');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_ENCODING , "gzip");
		$ret = curl_exec($ch);
		curl_close($ch);
        $res = simplexml_load_string($ret);
		if (!$this->isError($res)) return $res->token;
        return '';
    }
    
    /**
     * HiLink::getSessionToken()
     * Session token for login, version E8372 only
     * @return
     */
    public function getSessionToken(){
        $ch = curl_init($this->host.'/api/webserver/SesTokInfo');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_ENCODING , "gzip");
		$ret = curl_exec($ch);
		curl_close($ch);
        $res = simplexml_load_string($ret);
		if (!$this->isError($res)){
		  if (isset($res->SesInfo)){
		      $parts = explode("=",$res->SesInfo);
		      $this->sessionID=isset($parts[1]) ? $parts[1]: $parts[0];
		  }
          $this->requestToken=$res->TokInfo;
          return $res->TokInfo;
		} 
        return '';        
    }
    
    

    /**
     * HiLink::getErrorCode()
     * Returns the last error code
     * @return int
     */
    public function getErrorCode(){
        return @$this->lastError->code;
    }

    /**
     * HiLink::getErrorMessage()
     * Returns the last error message
     * @return string
     */
    public function getErrorMessage(){
        return @$this->lastError->message;
    }
    
    public function getErrorObject(){
        return $this->lastError;
    }
    
    public function login ($user, $pwd){
        if ($this->requestToken=='' || $this->sessionID=='') $this->getSessionToken();
        $password = base64_encode(hash('sha256', $user.base64_encode(hash('sha256', $pwd, false)).$this->requestToken, false));
        
        $req = new \SimpleXMLElement('<request></request>');
		$req->addChild('Username', $user);
        $req->addChild('Password', $password);
        $req->addChild('password_type',4);
  
		$opts = array(
			CURLOPT_POST => 1,
			CURLOPT_POSTFIELDS => $req->asXML());
		$ch = $this->init_curl($this->host.'/api/user/login', $opts);

   		$ret = curl_exec($ch);
		curl_close($ch);
		$res = simplexml_load_string($ret);
        return $this->isOK($res);
        
    }

	/* --- HELPER FUNCTIONS
	----------------------- */

    /**
     * HiLink::isOK()
     * Returns true if the $res is OK, if is error the lastError will be set
     * @param SimpleXMLElement $res
     * @return bool
     */
    private function isOK($res){
        return !$this->isError($res) && strtoupper($res->__toString())=='OK';
    }
    
    /**
     * HiLink::isError()
     * Returns is the $res is an error object and set the lastError
     * @param SimpleXMLElement $res
     * @return bool
     */
    private function isError($res){
        if(is_object($res) && $res->getName()=='error'){
            $this->lastError = $res;
            return true;
        }
        return false;
    }

    /**
     * HiLink::init_curl()
     * Common curl init function
     * @param string $url full url to download
     * @param array $options optional curl options
     * @return resource
     */
    private function init_curl($url, $options=null){
        $ch = curl_init($url);
        if (is_array($options)) curl_setopt_array($ch, $options);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_ENCODING , "gzip");
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, array ($this, 'curlHeaderCallback'));
        if ($this->useToken){
            $cookies = array();
            if ($this->api_version==API_VERSION_E303){
                $token = $this->getToken();
            }else{
                if ($this->sessionID=='' || $this->requestToken==''){
                   $this->getSessionToken(); 
                } 
                $token=$this->requestToken;
                $cookies[] = 'Cookie: SessionID='.$this->sessionID;
            }
            
            //print_r($this->api_version);
            //if ($this->sessionID!='') 
            //print_r(array_merge($cookies, $this->common_headers, array('__RequestVerificationToken: '.$token)));
            //echo PHP_EOL;
            //print_r($cookies);
            //echo PHP_EOL;
            //print_r($this->common_headers);
            //echo PHP_EOL;
            //print_r($token);
            //echo PHP_EOL;
            curl_setopt($ch,CURLOPT_HTTPHEADER, array_merge($cookies, $this->common_headers, array('__RequestVerificationToken: '.$token)));
        }
        return $ch;
    }
    
    protected function curlHeaderCallback($resURL, $header_line) {
  		/*
		* Not the prettiest way to parse it out, but hey it works.
		* If adding more or changing, remember the trim() call 
		* as the strings have nasty null bytes.
		*/
	    if(strpos($header_line, '__RequestVerificationTokenOne') === 0)
	    {
	    	$token = trim(substr($header_line, strlen('__RequestVerificationTokenOne:')));
	    	$this->requestTokenOne = $token;
	    }
	    elseif(strpos($header_line, '__RequestVerificationTokenTwo') === 0)
	    {
	    	$token = trim(substr($header_line, strlen('__RequestVerificationTokenTwo:')));
	    	$this->requestTokenTwo = $token;
	    }
	    elseif(strpos($header_line, '__RequestVerificationToken') === 0)
	    {
	    	$token = trim(substr($header_line, strlen('__RequestVerificationToken:')));
	    	$this->requestToken = $token;
	    }
	    elseif(strpos($header_line, 'Set-Cookie:') === 0)
	    {
	    	$cookie = trim(substr($header_line, strlen('Set-Cookie:')));
            $cookies= $this->parse_cookies($cookie);
            foreach ($cookies as $cookie){
                if ($cookie->name=="SessionID"){
                    $this->sessionID=$cookie->value;
                }
            }
	    }
	    return strlen($header_line);
    }

	/**
	 * HiLink::getSystem()
	 * Returns which system we are running
	 * @return string (mac, lnx, win)
	 */
	private function getSystem() {
		if (substr(__DIR__,0,1) == '/') {
			return (exec('uname') == 'Darwin') ? 'mac' : 'lnx';
		} else {
			return 'win';
		}
	}

	private function getTime($time) {
		$h = floor($time/3600);
		$m = floor($time/60) - $h*60;
		$s = $time - ($h*3600 + $m*60);

		if ($h < 10) $h = '0'.$h;
		if ($m < 10) $m = '0'.$m;
		if ($s < 10) $s = '0'.$s;

		return $h.':'.$m.':'.$s;
	}

	private function getData($bytes) {
		$kb = round($bytes/1024, 2);
		$mb = round($bytes/(1024*1024), 2);
		$gb = round($bytes/(1024*1024*1024), 2);

		if ($bytes > (1024*1024*1024)) return $gb." GB";
		if ($bytes > (1024*1024)) return $mb." MB";
		if ($bytes > 1024) return $kb." KB";
		else return $bytes." B";
	}
    
    protected function parse_cookies($header) {
    	$cookies = array();
    	
    	$cookie = new HiLinkcookie();
    	
    	$parts = explode("=",$header);
    	for ($i=0; $i< count($parts); $i++) {
    		$part = $parts[$i];
    		if ($i==0) {
    			$key = $part;
    			continue;
    		} elseif ($i== count($parts)-1) {
    			$cookie->set_value($key,$part);
    			$cookies[] = $cookie;
    			continue;
    		}
    		$comps = explode(" ",$part);
    		$new_key = $comps[count($comps)-1];
    		$value = substr($part,0,strlen($part)-strlen($new_key)-1);
    		$terminator = substr($value,-1);
    		$value = substr($value,0,strlen($value)-1);
    		$cookie->set_value($key,$value);
    		if ($terminator == ",") {
    			$cookies[] = $cookie;
    			$cookie = new HiLinkcookie();
    		}
    		
    		$key = $new_key;
    	}
    	return $cookies;
    }
}

//https://gist.github.com/pokeb/10590
class HiLinkcookie {
	public $name = "";
	public $value = "";
	public $expires = "";
	public $domain = "";
	public $path = "";
	public $secure = false;
	
	public function set_value($key,$value) {
		switch (strtolower($key)) {
			case "expires":
				$this->expires = $value;
				return;
			case "domain":
				$this->domain = $value;
				return;
			case "path":
				$this->path = $value;
				return;
			case "secure":
				$this->secure = ($value == true);
				return;
		}
		if ($this->name == "" && $this->value == "") {
			$this->name = $key;
			$this->value = $value;
		}
	}
}

?>

#!/usr/bin/env php
<?php

date_default_timezone_set('UTC');

require_once __DIR__.'/hilink.class.php';


//API_VERSION_E303 FOR 

//
$hilink = new AMWD\HiLink('192.168.8.1', null, 60, API_VERSION_E8372); 


//only if login required:
if ( $hilink->login("admin", "admin")){
    echo "Login OK";
}

/*echo "sms: ".var_export($hilink->getSmsInbox(), true);

var_export($hilink->getErrorObject(), true);

die ("ok");*/

echo "Host: ".$hilink->getHost().PHP_EOL;
echo "Online: ".$hilink->online().PHP_EOL;
if (!$hilink->online()) {
	echo "Abort: not online".PHP_EOL;
	exit;
}

echo PHP_EOL;

echo "External IP: ".$hilink->getExternalIp().PHP_EOL;
$hilink->printTraffic().PHP_EOL;
echo PHP_EOL;
$hilink->printStatus().PHP_EOL;

if ($hilink->getServiceStatus() == 'enter PIN') {
	echo "Enter PIN: ".$hilink->pinEnter(1234).PHP_EOL;
	sleep(2);
	$hilink->printStatus().PHP_EOL;
}

echo PHP_EOL;

$hilink->printPinStatus();

echo PHP_EOL;

echo "Connect: ".$hilink->connect().PHP_EOL;
sleep(5);
$hilink->printStatus();

while (!$hilink->isConnected()){
    sleep(5);
    echo "Waiting for connection...".PHP_EOL;
}

echo "Disconnect: ".$hilink->disconnect().PHP_EOL;
sleep(5);
echo "isConnected: ".$hilink->isConnected().PHP_EOL;

//echo "Switch to 2G: ".$hilink->setConnectionType('2g').PHP_EOL;
//sleep(10);
//echo "Check: ".$hilink->getConnectionType().PHP_EOL;
//sleep(2);
//echo "Switch to 3G: ".$hilink->setConnectionType('3g').PHP_EOL;
//sleep(10);
//echo "Check: ".$hilink->getConnectionType().PHP_EOL;

$hilink->printDeviceInfo();

echo PHP_EOL;

echo $hilink->getConnection();

//echo "Enable Autoconnect: ".$hilink->activateAutoconnect().PHP_EOL;
//echo "Disable Autoconnect: ".$hilink->deactivateAutoconnect().PHP_EOL;

echo PHP_EOL;

//print_r($hilink->createProfile('TestProfile', 'internet.eplus.de', 'eplus', 'internet')->asXML());
echo $hilink->listApn();

echo PHP_EOL;

$no = '0123456789012';

//echo "Send SMS to $no: ".$hilink->sendSms($no, 'Testmessage').PHP_EOL;

echo PHP_EOL;

//print_r($hilink->getSmsCount());

//print_r($hilink->sendSmsStatus());

$hilink->printSmsBox(); 

while ($hilink->online()){
        echo "Modem online ".date("d-m-Y H:i:s")." OK.<br/>\n";
        
        $inbox_list = $hilink->getSmsInbox(true);
        if ($inbox_list===false) {
            debug_print("Getting inbox list failed: ".var_export($hilink->getErrorObject(), true), __FILE__, __LINE__);
            throw new Exception("Getting Inbox");
        }
        
        foreach ($inbox_list as $sms){
            echo "Receiving 1 SMS from ".$sms['number']." Message: ".$sms['msg']."<br/>\n";
            $hilink->deleteSms($sms['idx']);
        }
        sleep (10);
}

//$hilink->deleteProfile(2);
//$hilink->deleteProfile(3);

//echo "Set SMS 20001 read: ".$hilink->setSmsRead('20001').PHP_EOL;

//print_r($hilink->listUnreadSms());

//for ($i = 20000; $i < 20006; $i++) {
//	echo "Delete $i: ".$hilink->deleteSms($i).PHP_EOL;
//}

//$hilink->printSmsBox();

?>

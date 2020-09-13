<?php  declare(strict_types=1);

//date_default_timezone_set('Asia/Tehran');
date_default_timezone_set('America/Los_Angeles');

use \danog\MadelineProto\Logger;
use \danog\MadelineProto\API;
use \danog\MadelineProto\Loop\Generic\GenericLoop;

if (!\file_exists('madeline.php')) {
    \copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
}
require 'madeline.php';

require_once 'functions.php';
require_once 'EventHandler.php'

if(!file_exists('data')) {
    mkdir('data');
}
if(!file_exists('./data/loopstate.json')) {
    file_put_contents('./data/loopstate.json', 'off');
}

$config      = getConfig();
$settings    = getSettings();
$credentials = getCredentials();

if (file_exists('MadelineProto.log') && $config['delete_log']) {
    unlink('MadelineProto.log');
}
if($credentials['api_id']??null && $credentials['api_hash']??null) {
    $settings['app_info']['api_id']   = $credentials['api_id']  ??null;
    $settings['app_info']['api_hash'] = $credentials['api_hash']??null;
}

$MadelineProto = new API('bot.madeline', $settings??[]);
$MadelineProto->async(true);

$genLoop = new GenericLoop(
    $MadelineProto,
    function () use($MadelineProto) {
        $eh = $MadelineProto->getEventHandler();
        if($eh->getLoopState()) {
            yield $MadelineProto->account->updateProfile([
                'about' => date('H:i:s')
            ]);
        }
        $delay = yield secondsToNexMinute($MadelineProto);
        return $delay; // Repeat around 60 seconds later
    },
    'Repeating Loop'
);

$maxRecycles = $config['max_recycles'];
safeStartAndLoop($MadelineProto, $genLoop, $maxRecycles);

exit("gone with the wind!");
<?php  declare(strict_types=1);
//date_default_timezone_set('Asia/Tehran');
date_default_timezone_set('America/Los_Angeles');

use \danog\MadelineProto\Logger;
use \danog\MadelineProto\API;
use \danog\MadelineProto\Tools;
use \danog\MadelineProto\Magic;
use \danog\MadelineProto\Shutdown;
use \danog\MadelineProto\Loop\Generic\GenericLoop;

if (!\file_exists('madeline.php')) {
    \copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
}
require 'madeline.php';

if(!file_exists('data')) {
    mkdir('data');
}
if(!file_exists('./data/loopstate.json')) {
    file_put_contents('./data/loopstate.json', 'off');
}

function getConfig(): array
{
    if(file_exists('config.php')) {
        $config = include 'config.php';
        $config['delete_log']   = $config['delete_log']??  false;
        $config['max_recycles'] = $config['max_recycles']?? 3;
        return $config;
    } else {
        return [
            'delete_log'   => true,
            'max_recycles' => 1
        ];
    }
}

function getCredentials(): array
{
    if(file_exists('credentials.php')) {
        return include 'credentials.php';
    } else {
        return [];
    }
}

function getSettings(): array
{
    if(file_exists('settings.php')) {
        return include 'settings.php';
    } else {
        return [];
    }
}

function secondsToNexMinute(): int
{
    $now   = hrtime()[0]; // time();
    $next  = intdiv($now + 60, 60) * 60;
    $delay = $next - $now;
    $delay = $delay > 60? 60 : $delay;
    return $delay; // in sec
}

function toJSON($var, bool $pretty = true): string {
    if( isset($var['request'])) {
        unset($var['request']);
    }
    $opts = JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES;
    $json = \json_encode($var, $opts | ($pretty? JSON_PRETTY_PRINT : 0));
    $json = ($json !== '')? $json : var_export($var, true);
    return $json;
}

function parseCommand(string $msg, string $prefixes = '!/', int $maxParams = 3): array
{
    $command = ['prefix' => '', 'verb' => '', 'params' => []];
    $msg = trim($msg);
    if($msg && strlen($msg) >= 2 && strpos($prefixes, $msg[0]) !== false) {
        $verb = strtolower(substr(rtrim($msg), 1, strpos($msg.' ', ' ') - 1));
        if(ctype_alnum($verb)) {
            $command['prefix'] = $msg[0];
            $command['verb']   = $verb;
            $tokens = explode(' ', $msg, $maxParams + 1);
            for($i = 1; $i < count($tokens); $i++) {
                $command['params'][$i - 1] = trim($tokens[$i]);
            }
        }
    }
    return $command;
}


class EventHandler extends \danog\MadelineProto\EventHandler
{
    private $config;
    private $robotID;
    private $ownerID;
    private $admins;
    private $startTime;
    private $reportPeers;
    private $loopState;
    private $processCommands;

    public function __construct(?\danog\MadelineProto\APIWrapper $API)
    {
        parent::__construct($API);
        $this->startTime   = time();
        $this->config      = getConfig();
        $this->admins      = [];
        $this->reportPeers = [];
        //$this->processCommands = false;
        $value = file_get_contents('data/loopstate.json');
        $this->loopState = $value === 'on'? true: false;
    }

    public function getReportPeers()
    {
        return [];
    }

    public function getRobotID(): int
    {
        return $this->robotID;
    }

    public function getLoopState(): bool
    {
        return $this->loopState;
    }
    public function setLoopState($loopState) {
        $this->loopState = $loopState;
        file_put_contents('data/loopstate.json', $loopState? 'on' : 'off');
    }

    public function onStart(): \Generator
    {
        $robot = yield $this->getSelf();
        $this->robotID = $robot['id'];

        if(isset($this->config['owner_id'])) {
            $this->ownerID = $this->config['owner_id'];
        }

        if(isset($this->config['report_peers'])) {
            foreach($this->config['report_peers'] as $reportPeer) {
                switch(strtolower($reportPeer)) {
                    case 'robot':
                        if(!in_array($this->robotID, $this->reportPeers)) {
                            array_push($this->reportPeers, $this->robotID);
                        }
                        break;
                    case 'owner':
                        if(isset($this->ownerID) && !in_array($this->ownerID, $this->reportPeers)) {
                            array_push($this->reportPeers, $this->ownerID);
                        }
                        break;
                    default:
                        if(!in_array($reportPeer, $this->reportPeers)) {
                            array_push($this->reportPeers, $reportPeer);
                        }
                        break;
                }
            }
        }
        $msg  = "Report Peers: ";
        foreach($this->reportPeers as $peer) {
            $msg .= $peer . '  ';
        }
        yield $this->logger($msg, Logger::ERROR);
        $this->setReportPeers($this->reportPeers);

        $this->processCommands = false;

        yield $this->messages->sendMessage([
            'peer'    => $this->robotID,
            'message' => "Robot just started."
        ]);
    }

    public function onUpdateEditMessage($update)
    {
        yield $this->onUpdateNewMessage($update);
    }
    public function onUpdateNewChannelMessage($update)
    {
        yield $this->onUpdateNewMessage($update);
    }
    public function onUpdateNewMessage($update)
    {
        if ($update['message']['_'] === 'messageService' ||
            $update['message']['_'] === 'messageEmpty')
        {
            return;
        }
        if(!isset($update['message']['message'])) {
            yield $this->echo("Empty message-text:<br>".PHP_EOL);
            yield $this->echo(toJSON($update).'<br>'.PHP_EOL);
            exit;
        }
        $msgOrig   = $update['message']['message']?? null;
        $msg       = $msgOrig? strtolower($msgOrig) : null;
        $messageId = $update['message']['id']?? 0;
        $fromId    = $update['message']['from_id']?? 0;
        $replyToId = $update['message']['reply_to_msg_id']?? 0;
        $isOutward = $update['message']['out']?? false;
        $peerType  = $update['message']['to_id']['_']?? '';
        $peer      = $update['message']['to_id']?? null;
        $byRobot   = $fromId    === $this->robotID && $msg;
        $toRobot   = $replyToId === $this->robotID && $msg;

        $command = parseCommand($msgOrig);
        $verb    = $command['verb']?? null;
        $params  = $command['params'];
        if($verb) {
            yield $this->echo(toJSON($command,  false).'<br>'.PHP_EOL);
        }

        //  log the messages of the robot, or a reply to a message sent by the robot.
        if($byRobot || $toRobot) {
            yield $this->logger("Arrived '$verb' ". ($this->processCommands? 'to be processed.' : 'to be ignored.'), Logger::ERROR);
            yield $this->logger(toJSON($update, false), Logger::ERROR);
        } else {
            //yield $this->logger(toJSON($update, false), Logger::ERROR);
        }

        if(($byRobot || $toRobot) && $msgOrig === 'Robot just started.') {
            yield $this->logger("Turned ProcessCommands on", Logger::ERROR);
            yield $this->echo("Turned ProcessCommands on.<br>".PHP_EOL);
            $this->processCommands = true;
        }

        if($byRobot && $verb && $this->processCommands) {
            switch($verb) {
                case 'help':
                    yield $this->messages->editMessage([
                        'peer'       => $peer,
                        'id'         => $messageId,
                        'message'    =>
                            "<b>Robot Instructions:</b><br>".
                          //"<br>".
                            ">> <b>/help</b><br>".
                            "   To print the robot commands' help.<br>".
                            ">> <b>/crash</b><br>".
                            "   To generate an exception for testing.<br>".
                            ">> <b>/loop</b> on/off/state<br>".
                            "   To query/change state of task repeater.<br>".
                            ">> <b>/status</b><br>".
                            "   To query the status of the robot.<br>".
                            ">> <b>/uptime</b><br>".
                            "   To query the robot's uptime.<br>" .
                            ">> <b>/memory</b><br>".
                            "   To query the robot's memory usage.<br>" .
                            ">> <b>/restart</b><br>".
                            "   To restart the robot.<br>".
                            ">> <b>/stop</b><br>".
                            "   To stop the script.<br>".
                            ">> <b>/logout</b><br>".
                            "   To terminate the robot's session.<br>".
                            "<br>".
                            "<b>**Valid prefixes are / and !</b><br>",
                        'parse_mode' => 'HTML',
                    ]);
                    break;
                case 'loop':
                    $param = strtolower($params[0]??'');
                    if(($param === 'on' || $param === 'off' || $param === 'state') && count($params) === 1) {
                        $loopStatePrev = $this->getLoopState();
                        $loopState = $param === 'on'? true : ($param === 'off'? false : $loopStatePrev);
                        yield $this->messages->editMessage([
                            'peer'    => $peer,
                            'id'      => $messageId,
                            'message' => 'The loop is ' . ($loopState? 'ON' : 'OFF') . '!',
                        ]);
                        if($loopState !== $loopStatePrev) {
                            $this->setLoopState($loopState);
                        }
                    } else {
                        yield $this->messages->editMessage([
                            'peer'    => $peer,
                            'id'      => $messageId,
                            'message' => "The argument must be 'on', 'off, or 'state'.",
                        ]);
                    }
                    break;
                case 'crash':
                    yield $this->logger("Purposefully crashing the script....", Logger::ERROR);
                    throw new \Exception('Artificial exception generated for testing the robot.');
                case 'status':
                    yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => 'The robot is online!',
                    ]);
                    break;
                case 'uptime':
                    $age     = time() - $this->startTime;
                    $days    = floor($age  / 86400);
                    $hours   = floor(($age / 3600) % 3600);
                    $minutes = floor(($age / 60) % 60);
                    $seconds = $age % 60;
                    $ageStr = sprintf("%02d:%02d:%02d:%02d", $days, $hours, $minutes, $seconds);
                    yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => "Robot's uptime is: ".$ageStr."."
                    ]);
                    break;
                case 'memory':
                    $memUsage = memory_get_usage(true);
                    if ($memUsage < 1024) {
                        $memUsage .= ' bytes';
                    } elseif ($memUsage < 1048576) {
                        $memUsage = round($memUsage/1024,2) . ' kilobytes';
                    } else {
                        $memUsage = round($memUsage/1048576,2) . ' megabytes';
                    }
                    yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => "Robot's memory usage is: ".$memUsage."."
                    ]);
                    break;
                case 'restart':
                    yield $this->logger('The robot re-started by the owner.', Logger::ERROR);
                    $result = yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => 'Restarting the robot ...',
                    ]);
                    $date = $result['date'];
                    $this->restart();
                    break;
                case 'logout':
                    yield $this->logger('the robot is logged out by the owner.', Logger::ERROR);
                    $result = yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => 'The robot is logging out. ...',
                    ]);
                    $date = $result['date'];
                    $this->logout();
                case 'stop':
                    $result = yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => 'Robot is stopping ...',
                    ]);
                    $date = $result['date'];
                    $json = toJSON($result);
                    yield $this->logger($json, Logger::ERROR);
                    if(Shutdown::removeCallback('restarter')) {
                        yield $this->logger('Self-Restarter disabled.', Logger::ERROR);
                    }
                    yield $this->stop();
                    break;
                default:
                    $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => 'Invalid command: '. "'".$verb."'",
                    ]);
                    break;
            } // enf of the command switch
        } // end of the commander qualification check
    } // end of function
} // end of the class

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

function safeStartAndLoop(API $MadelineProto, GenericLoop $genLoop = null, int $maxRecycles): void
{
    $recycleTimes = [];
    while(true) {
        try {
            $MadelineProto->loop(function () use ($MadelineProto, $genLoop) {
                yield $MadelineProto->start();
                yield $MadelineProto->setEventHandler('\EventHandler');
                if($genLoop !== null) {
                    /*yield*/ $genLoop->start(); // Do NOT use yield.
                }
                // Synchronously wait for the update loop to exit normally.
                // The update loop exits either on ->stop or ->restart (which also calls ->stop).
                Tools::wait(yield from $MadelineProto->API->loop());
                yield $MadelineProto->logger("Update loop exited!");
                //Magic::shutdown(1);
            });
            sleep(5);
            break;
        } catch (\Throwable $e) {
            try {
                $MadelineProto->logger->logger((string) $e, Logger::FATAL_ERROR);
                // quit recycling if more than $maxRecycles happened within the last minutes.
                $now = time();
                foreach($recycleTimes as $index => $restartTime) {
                    if($restartTime > $now - 1 * 60) {
                        break;
                    }
                    unset($recycleTimes[$index]);
                }
                if(count($recycleTimes) > $maxRecycles) {
                    // quit for good
                    Shutdown::removeCallback('restarter');
                    Magic::shutdown(1);
                    break;
                }
                $recycleTimes[] = $now;
                $MadelineProto->report("Surfaced: $e");
            }
            catch (\Throwable $e) {
            }
        }
    };
}

$maxRecycles = $config['max_recycles'];
safeStartAndLoop($MadelineProto, $genLoop, $maxRecycles);

exit("gone with the wind!");
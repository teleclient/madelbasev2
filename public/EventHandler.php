<?php  declare(strict_types=1);

use \danog\MadelineProto\Logger;
use \danog\MadelineProto\Shutdown;

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
            //yield $this->echo(toJSON($command,  false).'<br>'.PHP_EOL);
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
            //yield $this->echo("Turned ProcessCommands on.<br>".PHP_EOL);
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

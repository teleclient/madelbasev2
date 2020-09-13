<?php  declare(strict_types=1);

use \danog\MadelineProto\Logger;
use \danog\MadelineProto\API;
use \danog\MadelineProto\Tools;
use \danog\MadelineProto\Magic;
use \danog\MadelineProto\Shutdown;
use \danog\MadelineProto\Loop\Generic\GenericLoop;

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

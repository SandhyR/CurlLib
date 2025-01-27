<?php

declare(strict_types=1);

namespace VenityNetwork\CurlLib;

use pocketmine\Server;
use pocketmine\snooze\SleeperNotifier;
use pocketmine\thread\Thread;
use pocketmine\utils\Internet;
use pocketmine\utils\InternetException;
use Threaded;
use function gc_collect_cycles;
use function gc_enable;
use function gc_mem_caches;
use function json_encode;
use function serialize;
use function unserialize;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;

class CurlThread extends Thread{

    public bool $running = false;
    private Threaded $requests;
    private Threaded $responses;

    public function __construct(private \AttachableThreadedLogger $logger, private SleeperNotifier $notifier) {
        $this->requests = new Threaded();
        $this->responses = new Threaded();

        if(!CurlLib::isPackaged()){
            $cl = Server::getInstance()->getPluginManager()->getPlugin("DEVirion")->getVirionClassLoader();
            $this->setClassLoaders([Server::getInstance()->getLoader(), $cl]);
        }
    }

    public function onRun(): void{
        $this->running = true;
        while($this->running) {
            $this->processRequests();
            $this->wait();
        }
    }

    private function processRequests() {
        while(($request = $this->requests->shift()) !== null) {
            $request = unserialize($request);
            if($request instanceof CurlRequest) {
                $opts = $request->getCurlOpts();
                if($request->isPost()) {
                    $opts += [CURLOPT_POST => 1, CURLOPT_POSTFIELDS, $request->getPostField()];
                }
                try{
                    $result = Internet::simpleCurl($request->getUrl(), $request->getTimeout(), $request->getHeaders(), $opts);
                    $this->sendResponse(new CurlResponse($request->getId(), $result->getCode(), $result->getHeaders(), $result->getBody()));
                } catch(InternetException $e) {
                    $this->logger->error("CURL Error " . json_encode($request));
                    $this->logger->logException($e);
                    $this->sendResponse(new CurlResponse($request->getId(), exception: $e));
                }
            }elseif($request === "gc") {
                gc_enable();
                gc_collect_cycles();
                gc_mem_caches();
            }
        }
    }

    public function sendRequest(CurlRequest $request) {
        $this->requests[] = serialize($request);
        $this->notify();
    }

    private function sendResponse(CurlResponse $response) {
        $this->responses[] = serialize($response);
        $this->notifier->wakeupSleeper();
    }

    public function fetchResponse() : ?CurlResponse {
        $response = $this->responses->shift();
        return $response !== null ? unserialize($response) : null;
    }

    public function triggerGarbageCollector(){
        $this->requests[] = serialize("gc");
        $this->notify();
    }

    public function close() {
        $this->running = false;
        $this->notify();
    }
}
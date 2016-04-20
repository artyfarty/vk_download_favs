<?php
/**
 * @author artyfarty
 */

namespace Arty\VKDownloadFavs;


use Symfony\Component\Console\Output\OutputInterface;

class VkRateLimitWatcher {
    /**
     * @var float $lastRequestTimestamp
     */
    protected $lastRequestTimestamp = 0;

    /**
     * @var OutputInterface $logOutput
     */
    protected $logOutput;

    /**
     * @var float $cooldown
     */
    protected $cooldown;

    /**
     * VkRateLimitWatcher constructor.
     * @param int             $cooldown (usec)
     * @param OutputInterface $logOutput logger
     */
    public function __construct($cooldown = 500000, OutputInterface $logOutput = null) {
        $this->logOutput = $logOutput;
        $this->cooldown = $cooldown;
        $this->resetTimestamp();
    }


    protected function resetTimestamp() {
        $this->lastRequestTimestamp = microtime(true);
    }

    public function wantToRequest() {
        $now = microtime(true);
        $cooldownUsec  = $this->cooldown / 1000 / 1000;
        $difference = $now - $this->lastRequestTimestamp - $cooldownUsec;

        $toWait = abs(min($this->cooldown, $difference * 1000 * 1000));

        if ($difference < 0) {
            if ($this->logOutput) {
                $this->logOutput->writeln("[!] Rate limit danger! Will wait $toWait usec");
            }

            usleep($toWait);
        }

        $this->resetTimestamp();
    }
}
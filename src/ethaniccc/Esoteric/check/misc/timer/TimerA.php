<?php

namespace ethaniccc\Esoteric\check\misc\timer;

use ethaniccc\Esoteric\check\Check;
use ethaniccc\Esoteric\data\PlayerData;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\ServerboundPacket;

class TimerA extends Check{

	private ?float $lastTime = null;
	private int $balance = 0;

	public function __construct(){
		parent::__construct("Timer", "A", "Uses a 'balance' to determine if a player is using timer", false);
	}

	public function inbound(ServerboundPacket $packet, PlayerData $data) : void{
		if($packet instanceof PlayerAuthInputPacket){
			if(!$data->isAlive){
				$this->lastTime = null;
				$this->balance = 0;
				return;
			}
			$currentTime = microtime(true) * 1000;
			if($this->lastTime === null){
				$this->lastTime = $currentTime;
				return;
			}
			// convert the time difference into ticks (round this value to detect lower timer values).
			$timeDiff = round(($currentTime - $this->lastTime) / 50, 2);
			// there should be a one tick difference between the two packets
			$this->balance -= 1;
			// add the time difference between the two packet (this should be near one tick - which evens out the subtraction of one)
			$this->balance += $timeDiff;
			// if the balance is too low (the time difference is usually less than one tick)
			if($this->balance <= -5){
				$this->flag($data);
				$this->balance = 0;
			}
			$this->lastTime = $currentTime;
		}
	}

}
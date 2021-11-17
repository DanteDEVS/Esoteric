<?php

namespace ethaniccc\Esoteric\check\misc\packets;

use ethaniccc\Esoteric\check\Check;
use ethaniccc\Esoteric\data\PlayerData;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\ServerboundPacket;
use function abs;
use function floor;

class PacketsA extends Check{

	public function __construct(){
		parent::__construct("Packets", "A", "Checks if the player's pitch goes beyond a certain threshold", false);
	}

	public function inbound(ServerboundPacket $packet, PlayerData $data) : void{
		if($packet instanceof PlayerAuthInputPacket && abs($packet->getPitch()) > 92 && !$data->isFullKeyboardGameplay){
			$this->flag($data, ["pitch" => floor($packet->getPitch())]);
		}
	}

}
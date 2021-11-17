<?php

namespace ethaniccc\Esoteric\tasks;

use ethaniccc\Esoteric\data\PlayerData;
use ethaniccc\Esoteric\Esoteric;
use pocketmine\scheduler\Task;
use pocketmine\Server;
use function array_filter;

class TickingTask extends Task{

	public function onRun() : void{
		if(Server::getInstance()->getTick() % 40 === 0){
			Esoteric::getInstance()->hasAlerts = array_filter(Esoteric::getInstance()->dataManager->getAll(), static function(PlayerData $data) : bool{
				return $data->player !== null && !$data->player->isClosed() && $data->hasAlerts && $data->player->hasPermission("ac.alerts");
			});
		}
		foreach(Esoteric::getInstance()->dataManager->getAll() as $playerData){
			$playerData->tickProcessor->execute($playerData);
		}
	}

}
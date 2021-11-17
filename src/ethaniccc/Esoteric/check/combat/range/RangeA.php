<?php

namespace ethaniccc\Esoteric\check\combat\range;

use ethaniccc\Esoteric\check\Check;
use ethaniccc\Esoteric\data\PlayerData;
use ethaniccc\Esoteric\utils\AABB;
use ethaniccc\Esoteric\utils\Ray;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\ServerboundPacket;
use pocketmine\network\mcpe\protocol\types\GameMode;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use function max;
use function min;
use function round;

class RangeA extends Check{

	private bool $waiting = false;
	private int $secondaryBuffer = 0;

	public function __construct(){
		parent::__construct("Range", "A", "Checking if the player's attack range exceeds a certain limit", false);
	}

	public function inbound(ServerboundPacket $packet, PlayerData $data) : void{
		if($packet instanceof InventoryTransactionPacket && $packet->trData instanceof UseItemOnEntityTransactionData && $packet->trData->getActionType() === UseItemOnEntityTransactionData::ACTION_ATTACK && in_array($data->gamemode, [GameMode::SURVIVAL, GameMode::ADVENTURE])){
			$this->waiting = true;
		}elseif($packet instanceof PlayerAuthInputPacket && $this->waiting){
			$locationData = $data->entityLocationMap->get($data->target);
			if($locationData !== null){
				if($locationData->isSynced <= 30 || $data->ticksSinceTeleport <= 10 || $locationData->currentLocation->world->getId() !== $data->player->getWorld()->getId()){
					$this->waiting = false;
					return;
				}
				$AABB = AABB::fromPosition($locationData->lastLocation, $locationData->hitboxWidth + 0.1001, $locationData->hitboxHeight + 0.1001);
				$rawDistance = $AABB->distanceFromVector($data->attackPos);
				if($rawDistance > $this->option("max_raw", 3.05)){
					if(++$this->buffer >= 3){
						$this->flag($data, ["dist" => round($rawDistance, 3), "type" => "raw"]);
						$this->buffer = min($this->buffer, 4.5);
					}
				}else{
					$this->buffer = max($this->buffer - 0.04, 0);
				}
				if(!$data->isMobile && $locationData->isPlayer){
					$ray = new Ray($data->attackPos, $data->directionVector);
					$intersection = $AABB->calculateIntercept($ray->origin, $ray->traverse(7));
					if($intersection !== null && !$AABB->toAABB()->isVectorInside($data->attackPos) && !$AABB->toAABB()->intersectsWith($data->boundingBox->toAABB())){
						$raycastDist = $intersection->getHitVector()->distance($data->attackPos);
						if($raycastDist > $this->option("max_dist", 3.01)){
							if(++$this->secondaryBuffer >= 3){
								$this->flag($data, ["dist" => round($raycastDist, 3), "type" => "raycast"]);
								$this->secondaryBuffer = min($this->secondaryBuffer, 3);
							}
						}else{
							$this->secondaryBuffer = max($this->secondaryBuffer - 0.04, 0);
						}
					}
				}
			}
			$this->waiting = false;
		}
	}

}
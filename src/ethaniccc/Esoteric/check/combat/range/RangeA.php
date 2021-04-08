<?php

namespace ethaniccc\Esoteric\check\combat\range;

use ethaniccc\Esoteric\check\Check;
use ethaniccc\Esoteric\data\PlayerData;
use ethaniccc\Esoteric\utils\AABB;
use ethaniccc\Esoteric\utils\Ray;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;

class RangeA extends Check {

	private $waiting = false;

	public function __construct() {
		parent::__construct("Range", "A", "Checking if the player's attack range exceeds a certain limit", false);
	}

	public function inbound(DataPacket $packet, PlayerData $data): void {
		if ($packet instanceof InventoryTransactionPacket && $packet->transactionType === InventoryTransactionPacket::TYPE_USE_ITEM_ON_ENTITY && $packet->trData->actionType === InventoryTransactionPacket::USE_ITEM_ON_ENTITY_ACTION_ATTACK && !$data->isMobile) {
			$this->waiting = true;
		} elseif ($packet instanceof MovePlayerPacket && $this->waiting) {
			if ($data->currentTick - $data->attackTick <= 1) {
				$locationData = $data->entityLocationMap->get($data->target);
				if ($locationData !== null) {
					$ray = new Ray($data->attackPos, $data->directionVector);
					$AABB = AABB::fromPosition($locationData->lastLocation)->expand(0.105, 0.105, 0.105);
					$intersection = $AABB->calculateIntercept($ray->getOrigin(), $ray->traverse(20));
					$distance = $intersection === null ? -69 : ($AABB->isVectorInside($ray->getOrigin()) ? 0 : $intersection->getHitVector()->distance($ray->getOrigin()));
					if ($distance > 3.001 && $data->ticksSinceTeleport >= 10 && $locationData->isSynced >= 10) {
						if (++$this->buffer >= 2.5) {
							$this->flag($data, ["dist" => round($distance, 4), "buff" => round($this->buffer, 2)]);
							$this->buffer = min($this->buffer, 4);
						}
					} elseif ($distance !== -69) {
						$this->buffer = max($this->buffer - 0.025, 0);
						$this->reward(0.005);
					}

					/*if($distance !== -69){
						$roundedVector = $locationData->currentLocation->round(6);
						$data->player->sendMessage($distance > 3.001 ? TextFormat::RED . "dist=$distance buff={$this->buffer} x={$roundedVector->x} y={$roundedVector->y} z={$roundedVector->z}" : "dist=$distance buff={$this->buffer} x={$roundedVector->x} y={$roundedVector->y} z={$roundedVector->z}");
					}*/
				}
			}
			$this->waiting = false;
		}
	}

}
<?php

namespace ethaniccc\Esoteric\data\process;

use ethaniccc\Esoteric\data\PlayerData;
use ethaniccc\Esoteric\data\sub\effect\EffectData;
use pocketmine\entity\Attribute;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\AdventureSettingsPacket;
use pocketmine\network\mcpe\protocol\CorrectPlayerMovePredictionPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\MobEffectPacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\NetworkChunkPublisherUpdatePacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;
use pocketmine\network\mcpe\protocol\SetActorDataPacket;
use pocketmine\network\mcpe\protocol\SetActorMotionPacket;
use pocketmine\network\mcpe\protocol\SetPlayerGameTypePacket;
use pocketmine\network\mcpe\protocol\UpdateAttributesPacket;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\Server;
use pocketmine\timings\TimingsHandler;
use function is_null;

class ProcessOutbound {

	public static ?TimingsHandler $baseTimings = null;

	public function __construct() {
		if (is_null(self::$baseTimings)) {
			self::$baseTimings = new TimingsHandler("Esoteric Outbound Handling");
		}
	}

	public function execute(DataPacket $packet, PlayerData $data): void {
		self::$baseTimings->startTiming();
		$handler = NetworkStackLatencyHandler::getInstance();
		if ($packet instanceof MovePlayerPacket) {
			if ($packet->entityRuntimeId === $data->player->getId() && ($packet->mode === MovePlayerPacket::MODE_TELEPORT || $packet->mode === MovePlayerPacket::MODE_RESET)) {
				$handler->send($data, $handler->next($data), static function () use ($data): void {
					$data->ticksSinceTeleport = 0;
				});
			}
		} elseif ($packet instanceof UpdateBlockPacket) {
			$blockVector = new Vector3($packet->x, $packet->y, $packet->z);
			foreach ($data->inboundProcessor->placedBlocks as $key => $block) {
				// check if the block's position sent in UpdateBlockPacket is the same as the placed block
				// and if the block runtime ID sent in the packet equals the
				if ($blockVector->equals($block) && $block->getRuntimeId() === $packet->blockRuntimeId) {
					unset($data->inboundProcessor->placedBlocks[$key]);
					break;
				}
			}
		} elseif ($packet instanceof SetActorMotionPacket && $packet->entityRuntimeId === $data->player->getId()) {
			$handler->send($data, $handler->next($data), static function () use ($data, $packet): void {
				$data->motion = $packet->motion;
				$data->ticksSinceMotion = 0;
			});
		} elseif ($packet instanceof MobEffectPacket && $packet->entityRuntimeId === $data->player->getId()) {
			switch ($packet->eventId) {
				case MobEffectPacket::EVENT_ADD:
					$effectData = new EffectData();
					$effectData->effectId = $packet->effectId;
					$effectData->ticks = $packet->duration;
					$effectData->amplifier = $packet->amplifier + 1;
					$handler->send($data, $handler->next($data), static function () use ($data, $effectData): void {
						$data->effects[$effectData->effectId] = $effectData;
					});
					break;
				case MobEffectPacket::EVENT_MODIFY:
					$effectData = $data->effects[$packet->effectId] ?? null;
					if (is_null($effectData))
						return;
					$handler->send($data, $handler->next($data), static function () use (&$effectData, $packet): void {
						$effectData->amplifier = $packet->amplifier + 1;
						$effectData->ticks = $packet->duration;
					});
					break;
				case MobEffectPacket::EVENT_REMOVE:
					if (isset($data->effects[$packet->effectId])) {
						// removed before the effect duration has wore off client-side
						$handler->send($data, $handler->next($data), static function () use ($data, $packet): void {
							unset($data->effects[$packet->effectId]);
						});
					}
					break;
			}
		} elseif ($packet instanceof SetPlayerGameTypePacket) {
			$mode = $data->player->getGamemode();
			$handler->send($data, $handler->next($data), static function () use ($data, $mode): void {
				$data->gamemode = $mode;
			});
		} elseif ($packet instanceof SetActorDataPacket && $data->player->getId() === $packet->entityRuntimeId) {
			if ($data->immobile !== ($currentImmobile = $data->player->isImmobile())) {
				if ($data->loggedIn) {
					$handler->send($data, $handler->next($data), static function () use ($data, $currentImmobile): void {
						$data->immobile = $currentImmobile;
					});
				} else {
					$data->immobile = $currentImmobile;
				}
			}
			$AABB = $data->player->getBoundingBox();
			$hitboxWidth = ($AABB->maxX - $AABB->minX) * 0.5;
			$hitboxHeight = $AABB->maxY - $AABB->minY;
			if ($hitboxWidth !== $data->hitboxWidth) {
				$data->loggedIn ? $handler->send($data, $handler->next($data), static function () use ($data, $hitboxWidth): void {
					$data->hitboxWidth = $hitboxWidth;
				}) : $data->hitboxWidth = $hitboxWidth;
			}
			if ($hitboxHeight !== $data->hitboxWidth) {
				$data->loggedIn ? $handler->send($data, $handler->next($data), static function () use ($data, $hitboxHeight): void {
					$data->hitboxHeight = $hitboxHeight;
				}) : $data->hitboxHeight = $hitboxHeight;
			}
		} elseif ($packet instanceof NetworkChunkPublisherUpdatePacket) {
			if (!$data->loggedIn) {
				$data->inLoadedChunk = true;
				$data->chunkSendPosition = new Vector3($packet->x, $packet->y, $packet->z);
			} else {
				if ($data->chunkSendPosition->distance($data->currentLocation->floor()) > $data->player->getViewDistance() * 16) {
					$data->inLoadedChunk = false;
					$handler->send($data, $handler->next($data), function () use ($packet, $data): void {
						$data->inLoadedChunk = true;
						$data->chunkSendPosition = new Vector3($packet->x, $packet->y, $packet->z);
					});
				}
			}
		} elseif ($packet instanceof AdventureSettingsPacket) {
			$handler->send($data, $handler->next($data), static function () use ($packet, $data): void {
				$data->isFlying = $packet->getFlag(AdventureSettingsPacket::FLYING) || $packet->getFlag(AdventureSettingsPacket::NO_CLIP);
			});
		} elseif ($packet instanceof ActorEventPacket && $packet->entityRuntimeId === $data->player->getId()) {
			switch ($packet->event) {
				case ActorEventPacket::RESPAWN:
					$handler->send($data, $handler->next($data), static function () use ($data): void {
						$data->isAlive = true;
					});
					break;
			}
		} elseif ($packet instanceof UpdateAttributesPacket && $packet->entityRuntimeId === $data->player->getId()) {
			foreach ($packet->entries as $attribute) {
				if ($attribute->getId() === Attribute::HEALTH) {
					if ($attribute->getValue() <= 0) {
						$handler->send($data, $handler->next($data), static function () use ($data): void {
							$data->isAlive = false;
						});
					} elseif ($attribute->getValue() > 0 && !$data->isAlive) {
						$handler->send($data, $handler->next($data), static function () use ($data): void {
							$data->isAlive = true;
						});
					}
				}
			}
		} elseif ($packet instanceof CorrectPlayerMovePredictionPacket) {
			$handler->send($data, $handler->next($data), static function () use ($data): void {
				$data->ticksSinceTeleport = 0;
			});
		} elseif ($packet instanceof NetworkStackLatencyPacket) {
			$handler->forceSet($data, $packet->timestamp - fmod($packet->timestamp, 1000));
		} elseif ($packet instanceof RemoveActorPacket) {
			$handler->send($data, $handler->next($data), static function () use ($data, $packet): void {
				$data->entityLocationMap->removeEntity($packet->entityUniqueId);
			});
		} elseif ($packet instanceof AddActorPacket || $packet instanceof AddPlayerPacket) {
			$handler->send($data, $handler->next($data), static function () use ($data, $packet): void {
				$entity = Server::getInstance()->findEntity($packet->entityRuntimeId);
				if ($entity !== null) {
					// if the entity is null, the stupid client is out-of-sync (lag possibly)
					$data->entityLocationMap->addEntity($entity, $packet->position);
				}
			});
		}
		self::$baseTimings->stopTiming();
	}

}
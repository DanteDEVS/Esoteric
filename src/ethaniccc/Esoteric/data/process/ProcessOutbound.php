<?php

namespace ethaniccc\Esoteric\data\process;

use ethaniccc\Esoteric\data\PlayerData;
use ethaniccc\Esoteric\data\sub\effect\EffectData;
use pocketmine\entity\Attribute;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\AdventureSettingsPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\CorrectPlayerMovePredictionPacket;
use pocketmine\network\mcpe\protocol\MobEffectPacket;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\NetworkChunkPublisherUpdatePacket;
use pocketmine\network\mcpe\protocol\SetActorDataPacket;
use pocketmine\network\mcpe\protocol\SetActorMotionPacket;
use pocketmine\network\mcpe\protocol\SetPlayerGameTypePacket;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\UpdateAttributesPacket;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\timings\TimingsHandler;

class ProcessOutbound{

	public static ?TimingsHandler $baseTimings = null;

	public function __construct(){
		if(self::$baseTimings === null){
			self::$baseTimings = new TimingsHandler("Esoteric Outbound Handling");
		}
	}

	public function execute(ClientboundPacket $packet, PlayerData $data) : void{
		self::$baseTimings->startTiming();
		$handler = NetworkStackLatencyHandler::getInstance();
		if($packet instanceof MovePlayerPacket){
			if($packet->actorRuntimeId === $data->player->getId() && ($packet->mode === MovePlayerPacket::MODE_TELEPORT || $packet->mode === MovePlayerPacket::MODE_RESET)){
				$handler->queue($data, static function(int $timestamp) use ($data) : void{
					$data->ticksSinceTeleport = 0;
				});
			}elseif($packet->actorRuntimeId !== $data->player->getId()){
				$data->entityLocationMap->add($packet);
			}
		}elseif($packet instanceof MoveActorAbsolutePacket){
			$data->entityLocationMap->add($packet);
		}elseif($packet instanceof UpdateBlockPacket){
			$blockVector = new Vector3($packet->blockPosition->getX(), $packet->blockPosition->getY(), $packet->blockPosition->getZ());
			foreach($data->inboundProcessor->queuedBlocks as $key => $block){
				// check if the block's position sent in UpdateBlockPacket is the same as the placed block
				// and if the block runtime ID sent in the packet equals the
				if($blockVector->equals($block->getPosition())){
					// problems with meta screwing over this check
					if($block->getFullId() !== RuntimeBlockMapping::getInstance()->fromRuntimeId($packet->blockRuntimeId)){
						$data->inboundProcessor->needUpdateBlocks[] = $block;
					}
					unset($data->inboundProcessor->queuedBlocks[$key]);
				}
			}
			$block = RuntimeBlockMapping::getInstance()->fromRuntimeId($packet->blockRuntimeId);
			/* if ($block >> 4 === BlockLegacyIds::FENCE && $block & 0xf === 7) {
				$block = (BlockLegacyIds::FENCE << 4) | 0; // TODO: There has to be a better way to get around this than just hardcoding...
			} */
			NetworkStackLatencyHandler::getInstance()->queue($data, function(int $timestamp) use ($data, $blockVector, $block) : void{
				$data->world->setBlock($blockVector, $block);
			});
		}elseif($packet instanceof SetActorMotionPacket && $packet->actorRuntimeId === $data->player->getId()){
			$handler->queue($data, static function(int $timestamp) use ($data, $packet) : void{
				$data->motion = $packet->motion;
				$data->ticksSinceMotion = 0;
			});
		}elseif($packet instanceof MobEffectPacket && $packet->actorRuntimeId === $data->player->getId()){
			switch($packet->eventId){
				case MobEffectPacket::EVENT_ADD:
					$effectData = new EffectData();
					$effectData->effectId = $packet->effectId;
					$effectData->ticks = $packet->duration;
					$effectData->amplifier = $packet->amplifier + 1;
					$handler->queue($data, static function(int $timestamp) use ($data, $effectData) : void{
						$data->effects[$effectData->effectId] = $effectData;
					});
					break;
				case MobEffectPacket::EVENT_MODIFY:
					$effectData = $data->effects[$packet->effectId] ?? null;
					if($effectData === null){
						return;
					}
					$handler->queue($data, static function(int $timestamp) use (&$effectData, $packet) : void{
						$effectData->amplifier = $packet->amplifier + 1;
						$effectData->ticks = $packet->duration;
					});
					break;
				case MobEffectPacket::EVENT_REMOVE:
					if(isset($data->effects[$packet->effectId])){
						// removed before the effect duration has wore off client-side
						$handler->queue($data, static function(int $timestamp) use ($data, $packet) : void{
							unset($data->effects[$packet->effectId]);
						});
					}
					break;
			}
		}elseif($packet instanceof SetPlayerGameTypePacket){
			$mode = $packet->gamemode;
			$handler->queue($data, static function(int $timestamp) use ($data, $mode) : void{
				$data->gamemode = $mode;
			});
		}elseif($packet instanceof SetActorDataPacket && $data->player->getId() === $packet->actorRuntimeId){
			if($data->immobile !== ($currentImmobile = $data->player->isImmobile())){
				if($data->loggedIn){
					$handler->queue($data, static function(int $timestamp) use ($data, $currentImmobile) : void{
						$data->immobile = $currentImmobile;
					});
				}else{
					$data->immobile = $currentImmobile;
				}
			}
			$AABB = $data->player->getBoundingBox();
			$hitboxWidth = ($AABB->maxX - $AABB->minX) * 0.5;
			$hitboxHeight = $AABB->maxY - $AABB->minY;
			if($hitboxWidth !== $data->hitboxWidth){
				$data->loggedIn ? $handler->queue($data, static function(int $timestamp) use ($data, $hitboxWidth) : void{
					$data->hitboxWidth = $hitboxWidth;
				}) : $data->hitboxWidth = $hitboxWidth;
			}
			if($hitboxHeight !== $data->hitboxWidth){
				$data->loggedIn ? $handler->queue($data, static function(int $timestamp) use ($data, $hitboxHeight) : void{
					$data->hitboxHeight = $hitboxHeight;
				}) : $data->hitboxHeight = $hitboxHeight;
			}
		}elseif($packet instanceof NetworkChunkPublisherUpdatePacket){
			NetworkStackLatencyHandler::getInstance()->queue($data, function(int $timestamp) use ($data, $packet) : void{
				$data->chunkSendPosition = new Vector3($packet->blockPosition->getX(), $packet->blockPosition->getY(), $packet->blockPosition->getZ());
				$radius = $packet->radius >> 4;
				$chunkX = $data->chunkSendPosition->x >> 4;
				$chunkZ = $data->chunkSendPosition->z >> 4;
				foreach($data->world->getAllChunks() as $chunkData){
					if(abs($chunkData->getX() - $chunkX) > $radius || /** <- this should be an OR or an AND? */ abs($chunkData->getZ() - $chunkZ) > $radius){
						$data->world->removeChunk($chunkData->getX(), $chunkData->getZ());
					}
				}
			});
		}elseif($packet instanceof AdventureSettingsPacket){
			$handler->queue($data, static function(int $timestamp) use ($packet, $data) : void{
				$data->isFlying = $packet->getFlag(AdventureSettingsPacket::FLYING) || $packet->getFlag(AdventureSettingsPacket::NO_CLIP);
			});
		}elseif($packet instanceof ActorEventPacket && $packet->actorRuntimeId === $data->player->getId()){
			switch($packet->eventId){
				case ActorEvent::RESPAWN:
					$handler->queue($data, static function(int $timestamp) use ($data) : void{
						$data->isAlive = true;
					});
					break;
			}
		}elseif($packet instanceof UpdateAttributesPacket && $packet->actorRuntimeId === $data->player->getId()){
			foreach($packet->entries as $attribute){
				if($attribute->getId() === Attribute::HEALTH){
					if($attribute->getCurrent() <= 0){
						$handler->queue($data, static function(int $timestamp) use ($data) : void{
							$data->isAlive = false;
						});
					}elseif($attribute->getCurrent() > 0 && !$data->isAlive){
						$handler->queue($data, static function(int $timestamp) use ($data) : void{
							$data->isAlive = true;
						});
					}
				}
			}
		}elseif($packet instanceof CorrectPlayerMovePredictionPacket){
			$handler->queue($data, static function(int $timestamp) use ($data) : void{
				$data->ticksSinceTeleport = 0;
			});
		}
		self::$baseTimings->stopTiming();
	}

}
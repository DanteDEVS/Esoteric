<?php

namespace ethaniccc\Esoteric\data\process;

use ethaniccc\Esoteric\data\PlayerData;
use ethaniccc\Esoteric\data\sub\movement\MovementConstants;
use ethaniccc\Esoteric\data\sub\protocol\InputConstants;
use ethaniccc\Esoteric\data\sub\protocol\v428\PlayerAuthInputPacket;
use ethaniccc\Esoteric\data\sub\protocol\v428\PlayerBlockAction;
use ethaniccc\Esoteric\Esoteric;
use ethaniccc\Esoteric\utils\AABB;
use ethaniccc\Esoteric\utils\EvictingList;
use ethaniccc\Esoteric\utils\LevelUtils;
use ethaniccc\Esoteric\utils\MathUtils;
use ethaniccc\Esoteric\utils\PacketUtils;
use pocketmine\block\Block;
use pocketmine\block\BlockIds;
use pocketmine\block\Cobweb;
use pocketmine\block\Ladder;
use pocketmine\block\Liquid;
use pocketmine\block\UnknownBlock;
use pocketmine\block\Vine;
use pocketmine\entity\Effect;
use pocketmine\level\Location;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\protocol\AdventureSettingsPacket;
use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\PacketViolationWarningPacket;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\network\mcpe\protocol\SetLocalPlayerAsInitializedPacket;
use pocketmine\network\mcpe\protocol\types\DeviceOS;
use pocketmine\network\mcpe\protocol\types\GameMode;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemTransactionData;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\tile\Spawnable;
use pocketmine\timings\TimingsHandler;
use function abs;
use function array_shift;
use function ceil;
use function count;
use function floor;
use function fmod;
use function in_array;
use function is_null;
use function round;

final class ProcessInbound {

	public static ?TimingsHandler $timings = null;
	public static ?TimingsHandler $inventoryTransactionTimings;
	public static ?TimingsHandler $networkStackLatencyTimings;
	public static ?TimingsHandler $movementTimings;
	public static ?TimingsHandler $collisionTimings;
	/** @var Block[] */
	public array $placedBlocks = [];

	private Vector3 $lastClientPrediction;
	private EvictingList $pitchRotationSamples;
	private EvictingList $yawRotationSamples;

	public function __construct() {
		if (is_null(self::$timings)) {
			self::$timings = new TimingsHandler("Esoteric Inbound Handling");
			self::$movementTimings = new TimingsHandler("Esoteric Movement Handling", self::$timings);
			self::$collisionTimings = new TimingsHandler("Esoteric Movement Collisions", self::$movementTimings);
			self::$inventoryTransactionTimings = new TimingsHandler("Esoteric Transaction Handling", self::$timings);
			self::$networkStackLatencyTimings = new TimingsHandler("Esoteric NetworkStackLatency Handling", self::$timings);
		}
		$this->yawRotationSamples = new EvictingList(10);
		$this->pitchRotationSamples = new EvictingList(10);
		$this->lastClientPrediction = new Vector3(0, -0.08 * 0.98, 0);
	}

	public function execute(DataPacket $packet, PlayerData $data): void {
		self::$timings->startTiming();
		if ($packet instanceof PlayerAuthInputPacket && $data->loggedIn) {
			$data->packetDeltas[$packet->getTick()] = $packet->getDelta();
			if (count($data->packetDeltas) > 20) {
				array_shift($data->packetDeltas);
			}
			$location = Location::fromObject($packet->getPosition()->subtract(0, 1.62), $data->player->getLevel(), $packet->getYaw(), $packet->getPitch());
			$data->inLoadedChunk = $data->chunkSendPosition->distance($data->currentLocation->floor()) <= $data->player->getViewDistance() * 16;
			$data->teleported = false;
			$data->hasMovementSuppressed = false;
			$data->lastLocation = clone $data->currentLocation;
			$data->currentLocation = $location;
			$data->lastMoveDelta = $data->currentMoveDelta;
			$data->currentMoveDelta = $data->currentLocation->subtract($data->lastLocation)->asVector3();
			$data->previousYaw = $data->currentYaw;
			$data->previousPitch = $data->currentPitch;
			$data->currentYaw = $location->yaw;
			$data->currentPitch = $location->pitch;
			$data->lastYawDelta = $data->currentYawDelta;
			$data->lastPitchDelta = $data->currentPitchDelta;
			$data->currentYawDelta = abs($data->currentYaw - $data->previousYaw);
			$data->currentPitchDelta = abs($data->currentPitch - $data->previousPitch);
			if ($data->currentYawDelta > 180) {
				$data->currentYawDelta = 360 - $data->currentYawDelta;
			}
			if ($data->currentYawDelta > 0) {
				$this->yawRotationSamples->add($data->currentYawDelta);
				if ($this->yawRotationSamples->full()) {
					$count = 0;
					$this->yawRotationSamples->iterate(static function (float $delta) use(&$count): void {
						$fullKeyboardSens = round(round($delta, 2) * MovementConstants::FULL_KEYBOARD_ROTATION_MULTIPLIER, 3);
						if (fmod($fullKeyboardSens, 1) <= 1E-7) {
							++$count;
						}
					});
					$passedYaw = true;
					$data->isFullKeyboardGameplay = $count > 0;
					$this->yawRotationSamples->clear();
				}
			}
			if ($data->currentPitchDelta > 0) {
				$this->pitchRotationSamples->add($data->currentPitchDelta);
				if ($this->pitchRotationSamples->full()) {
					$count = 0;
					$this->pitchRotationSamples->iterate(static function (float $delta) use(&$count): void {
						$fullKeyboardSens = round(round($delta, 2) * MovementConstants::FULL_KEYBOARD_ROTATION_MULTIPLIER, 3);
						if (fmod($fullKeyboardSens, 1) <= 1E-7) {
							++$count;
						}
					});
					$data->isFullKeyboardGameplay = isset($passedYaw) || $count > 0;
					$this->pitchRotationSamples->clear();
				}
			}
			$data->boundingBox = AABB::from($data);
			$data->directionVector = MathUtils::directionVectorFromValues($data->currentYaw, $data->currentPitch);
			$validMovement = $data->currentMoveDelta->lengthSquared() >= MovementConstants::MOVEMENT_THRESHOLD_SQUARED;

			if ($validMovement || $data->currentYawDelta > 0 || $data->currentPitchDelta > 0) {
				$pk = new MovePlayerPacket();
				$pk->entityRuntimeId = $data->player->getId();
				$pk->position = $packet->getPosition();
				$pk->yaw = $location->yaw;
				$pk->headYaw = $packet->getHeadYaw();
				$pk->pitch = $location->pitch;
				$pk->mode = MovePlayerPacket::MODE_NORMAL;
				$pk->onGround = $data->onGround;
				$pk->tick = $packet->getTick();
				$data->player->handleMovePlayer($pk);
			}

			if (InputConstants::hasFlag($packet, InputConstants::START_SPRINTING)) {
				$pk = new PlayerActionPacket();
				$pk->entityRuntimeId = $data->player->getId();
				$pk->action = PlayerActionPacket::ACTION_START_SPRINT;
				$pk->x = $location->x;
				$pk->y = $location->y;
				$pk->z = $location->z;
				$pk->face = $data->player->getDirection();
				$data->player->handlePlayerAction($pk);
			}
			if (InputConstants::hasFlag($packet, InputConstants::STOP_SPRINTING)) {
				$pk = new PlayerActionPacket();
				$pk->entityRuntimeId = $data->player->getId();
				$pk->action = PlayerActionPacket::ACTION_STOP_SPRINT;
				$pk->x = $location->x;
				$pk->y = $location->y;
				$pk->z = $location->z;
				$pk->face = $data->player->getDirection();
				$data->player->handlePlayerAction($pk);
			}
			if (InputConstants::hasFlag($packet, InputConstants::START_SNEAKING)) {
				$pk = new PlayerActionPacket();
				$pk->entityRuntimeId = $data->player->getId();
				$pk->action = PlayerActionPacket::ACTION_START_SNEAK;
				$pk->x = $location->x;
				$pk->y = $location->y;
				$pk->z = $location->z;
				$pk->face = $data->player->getDirection();
				$data->player->handlePlayerAction($pk);
			}
			if (InputConstants::hasFlag($packet, InputConstants::STOP_SNEAKING)) {
				$pk = new PlayerActionPacket();
				$pk->entityRuntimeId = $data->player->getId();
				$pk->action = PlayerActionPacket::ACTION_STOP_SNEAK;
				$pk->x = $location->x;
				$pk->y = $location->y;
				$pk->z = $location->z;
				$pk->face = $data->player->getDirection();
				$data->player->handlePlayerAction($pk);
			}
			if (InputConstants::hasFlag($packet, InputConstants::START_JUMPING)) {
				$data->ticksSinceJump = 0;
				$pk = new PlayerActionPacket();
				$pk->entityRuntimeId = $data->player->getId();
				$pk->action = PlayerActionPacket::ACTION_JUMP;
				$pk->x = $location->x;
				$pk->y = $location->y;
				$pk->z = $location->z;
				$pk->face = $data->player->getDirection();
				$data->player->handlePlayerAction($pk);
			}
			if (InputConstants::hasFlag($packet, InputConstants::START_GLIDING)) {
				$data->isGliding = true;
			}
			if (InputConstants::hasFlag($packet, InputConstants::STOP_GLIDING)) {
				$data->isGliding = false;
			}

			if ($packet->blockActions !== null) {
				foreach ($packet->blockActions as $action) {
					switch ($action->actionType) {
						case PlayerBlockAction::START_BREAK:
							$pk = new PlayerActionPacket();
							$pk->entityRuntimeId = $data->player->getId();
							$pk->action = PlayerActionPacket::ACTION_START_BREAK;
							$pk->x = $action->blockPos->x;
							$pk->y = $action->blockPos->y;
							$pk->z = $action->blockPos->z;
							$pk->face = $data->player->getDirection();
							$data->player->handlePlayerAction($pk);
							break;
						case PlayerBlockAction::CONTINUE:
						case PlayerBlockAction::CRACK_BREAK:
							$pk = new PlayerActionPacket();
							$pk->entityRuntimeId = $data->player->getId();
							$pk->action = PlayerActionPacket::ACTION_CRACK_BREAK;
							$pk->x = $action->blockPos->x;
							$pk->y = $action->blockPos->y;
							$pk->z = $action->blockPos->z;
							$pk->face = $data->player->getDirection();
							$data->player->handlePlayerAction($pk);
							break;
						case PlayerBlockAction::ABORT_BREAK:
							$pk = new PlayerActionPacket();
							$pk->entityRuntimeId = $data->player->getId();
							$pk->action = PlayerActionPacket::ACTION_ABORT_BREAK;
							$pk->x = $action->blockPos->x;
							$pk->y = $action->blockPos->y;
							$pk->z = $action->blockPos->z;
							$pk->face = $data->player->getDirection();
							$data->player->handlePlayerAction($pk);
							break;
						case PlayerBlockAction::STOP_BREAK:
							$pk = new PlayerActionPacket();
							$pk->entityRuntimeId = $data->player->getId();
							$pk->action = PlayerActionPacket::ACTION_STOP_BREAK;
							$pk->x = $location->x;
							$pk->y = $location->y;
							$pk->z = $location->z;
							$pk->face = $data->player->getDirection();
							$data->player->handlePlayerAction($pk);
							break;
						case PlayerBlockAction::PREDICT_DESTROY:
							break;
					}
				}
			}

			if ($packet->itemInteractionData !== null) {
				// maybe if :microjang: didn't make the block breaking server-side option redundant, I wouldn't be doing this... you know who to blame !
				// hahaha... skidding PMMP go brrrt
				$player = $data->player;
				$player->doCloseInventory();
				$item = $player->getInventory()->getItemInHand();
				$oldItem = clone $item;
				$canInteract = $player->canInteract($packet->itemInteractionData->blockPos->add(0.5, 0.5, 0.5), $player->isCreative() ? 13 : 7);
				$useBreakOn = $player->getLevel()->useBreakOn($packet->itemInteractionData->blockPos, $item, $player, true);
				if ($canInteract and $useBreakOn) {
					if ($player->isSurvival()) {
						if (!$item->equalsExact($oldItem) and $oldItem->equalsExact($player->getInventory()->getItemInHand())) {
							$player->getInventory()->setItemInHand($item);
							$player->getInventory()->sendHeldItem($player->getViewers());
						}
					}
				} else {
					$player->getInventory()->sendContents($player);
					$player->getInventory()->sendHeldItem($player);
					$target = $player->getLevel()->getBlock($packet->itemInteractionData->blockPos);
					$blocks = $target->getAllSides();
					$blocks[] = $target;
					$player->getLevel()->sendBlocks([$player], $blocks, UpdateBlockPacket::FLAG_ALL_PRIORITY);
					foreach ($blocks as $b) {
						$tile = $player->getLevel()->getTile($b);
						if ($tile instanceof Spawnable) {
							$tile->spawnTo($player);
						}
					}
				}
			}

			$data->jumpVelocity = MovementConstants::DEFAULT_JUMP_MOTION;
			$data->canPlaceBlocks = $data->gamemode === GameMode::SURVIVAL || $data->gamemode === GameMode::CREATIVE;

			foreach ($data->effects as $effectData) {
				$effectData->ticks--;
				if ($effectData->ticks <= 0) {
					unset($data->effects[$effectData->effectId]);
				} else {
					switch ($effectData->effectId) {
						case Effect::JUMP_BOOST:
							$data->jumpVelocity = MovementConstants::DEFAULT_JUMP_MOTION + ($effectData->amplifier / 10);
							break;
						case Effect::LEVITATION:
							$data->ticksSinceFlight = 0;
							break;
					}
				}
			}

			if ($validMovement) {
				self::$collisionTimings->startTiming();
				// LevelUtils::checkBlocksInAABB() is basically a duplicate of getCollisionBlocks, but in here, it will get all blocks
				// if the block doesn't have an AABB, this assumes a 1x1x1 AABB for that block
				$blocks = LevelUtils::checkBlocksInAABB($data->boundingBox->expandedCopy(0.5, 0.5, 0.5), $location->getLevel(), LevelUtils::SEARCH_ALL, false);
				$data->expectedOnGround = false;
				$data->lastBlocksBelow = $data->blocksBelow;
				$data->blocksBelow = [];
				$data->isCollidedHorizontally = false;
				$data->isCollidedVertically = false;
				$liquids = 0;
				$climbable = 0;
				$cobweb = 0;
				// TODO: Improve vertical and horizontal collisions to work with checks (pReDicTioN bRo)
				$horizontalAABB = $data->boundingBox->expandedCopy(0.25, 0, 0.25);
				$verticalAABB = $data->boundingBox->expandedCopy(0.25, MovementConstants::GROUND_MODULO * 2, 0.25);
				/** @var Block $block */
				foreach ($blocks as $block) {
					if (!$data->isCollidedHorizontally) {
						// snow layers are evil
						$data->isCollidedHorizontally = $block->getId() !== BlockIds::AIR && (count($block->getCollisionBoxes()) === 0 ? AABB::fromBlock($block)->intersectsWith($horizontalAABB) : $block->collidesWithBB($horizontalAABB));
					}
					if ($block->getId() !== BlockIds::AIR && (count($block->getCollisionBoxes()) === 0 ? AABB::fromBlock($block)->intersectsWith($verticalAABB) : $block->collidesWithBB($verticalAABB))) {
						$data->isCollidedVertically = true;
						if (floor($block->y) <= floor($location->y)) {
							$data->expectedOnGround = true;
							$data->blocksBelow[] = $block;
						}
					}
					if ($block instanceof Liquid) {
						$liquids++;
					} elseif ($block instanceof Cobweb) {
						$cobweb++;
					} elseif ($block instanceof Ladder || $block instanceof Vine) {
						$climbable++;
					}
				}
				if ($liquids > 0) $data->ticksSinceInLiquid = 0; else ++$data->ticksSinceInLiquid;
				if ($cobweb > 0) $data->ticksSinceInCobweb = 0; else ++$data->ticksSinceInCobweb;
				if ($climbable > 0) $data->ticksSinceInClimbable = 0; else ++$data->ticksSinceInClimbable;
				self::$collisionTimings->stopTiming();
				// $expectedMoveY = ($data->lastMoveDelta->y - MovementConstants::Y_SUBTRACTION) * MovementConstants::Y_MULTIPLICATION;
				$actualMoveY = $data->currentMoveDelta->y;
				$predictedMoveY = $this->lastClientPrediction->y;
				if($data->ticksSinceMotion === 0) $predictedMoveY = $data->motion->y;
				if($data->ticksSinceJump === 0) $predictedMoveY = $data->jumpVelocity;
				$flag3 = abs($predictedMoveY - $actualMoveY) > 0.001;
				$flag4 = $predictedMoveY < 0 || $data->isCollidedHorizontally;
				$data->hasBlockAbove = $flag3 && $predictedMoveY > 0 && abs($predictedMoveY) > 0.005 && $data->isCollidedVertically;
				$data->onGround = $flag3 && $flag4 && $data->expectedOnGround;

				if($data->ticksSinceTeleport <= 1) $data->onGround = true;
				if($data->onGround && $data->isGliding) $data->isGliding = false;
			}

			if ($data->teleported || !$data->inLoadedChunk) {
				$data->expectedOnGround = true; // might need to remove this
				$data->onGround = true;
			}

			/**
			 * Checks if there is a possible ghost block that the player is standing on. If there is a ghost block that the player is standing on,
			 * we should remove it to prevent possible false-flags with a GroundSpoof check.
			 */

			// TODO: There's a stupid bug where setting a block with UpdateBlockPacket won't do anything, make future attempts to fix this BS.

			foreach ($this->placedBlocks as $blockVector) {
				$expand = 0.1;
				$hasCollision = count($blockVector->getCollisionBoxes()) === 0 ? AABB::fromBlock($blockVector)->intersectsWith($data->boundingBox->expandedCopy($expand, $expand, $expand)) : $blockVector->collidesWithBB($data->boundingBox->expandedCopy($expand, $expand, $expand));
				if ($hasCollision) {
					$data->expectedOnGround = true;
					$data->onGround = true;
					$data->isCollidedHorizontally = $blockVector->y >= floor($location->y) && $blockVector->y - $location->y <= ceil($data->hitboxHeight);
					$data->isCollidedVertically = true;
					$data->blocksBelow[] = $blockVector;
				}
				if ($validMovement || $hasCollision) {
					$realBlock = $data->player->getLevel()->getBlock($blockVector, false, false);
					$handler = NetworkStackLatencyHandler::getInstance();
					$handler->send($data, $handler->next($data), function () use ($hasCollision, $data, $realBlock, $handler): void {
						$p = new BatchPacket();
						$pk = new UpdateBlockPacket();
						$pk->x = $realBlock->x;
						$pk->y = $realBlock->y;
						$pk->z = $realBlock->z;
						if ($realBlock instanceof Liquid) {
							$pk->blockRuntimeId = RuntimeBlockMapping::toStaticRuntimeId(0);
							$pk->flags = UpdateBlockPacket::FLAG_ALL_PRIORITY;
							$pk->dataLayerId = UpdateBlockPacket::DATA_LAYER_NORMAL;
							$p->addPacket($pk);
							$pk = new UpdateBlockPacket();
							$pk->x = $realBlock->x;
							$pk->y = $realBlock->y;
							$pk->z = $realBlock->z;
						}
						$pk->blockRuntimeId = $realBlock->getRuntimeId();
						$pk->flags = UpdateBlockPacket::FLAG_ALL_PRIORITY;
						$pk->dataLayerId = UpdateBlockPacket::DATA_LAYER_NORMAL;
						$p->addPacket($pk);
						$n = $handler->next($data);
						$p->addPacket($n);
						$p->encode();
						PacketUtils::sendPacketSilent($data, $p);
						if ($hasCollision && floor($data->currentLocation->y) > $realBlock->y) {
							// prevent the player from possibly false flagging when removing ghost blocks fail
							$teleportPos = $realBlock->asVector3();
							$teleportPos->x = $data->currentLocation->x;
							$teleportPos->z = $data->currentLocation->z;
							$data->player->teleport($teleportPos);
						}
						$handler->forceHandle($data, $n->timestamp, function () use ($data, $realBlock): void {
							foreach ($this->placedBlocks as $key => $vector) {
								if ($vector->equals($realBlock->asVector3())) {
									unset($this->placedBlocks[$key]);
									break;
								}
							}
						});
					});
				}
			}

			if ($data->onGround) {
				++$data->onGroundTicks;
				$data->offGroundTicks = 0;
				$data->lastOnGroundLocation = clone $data->currentLocation;
			} else {
				++$data->offGroundTicks;
				$data->onGroundTicks = 0;
			}
			++$data->ticksSinceMotion;
			if ($data->ticksSinceTeleport <= 1) {
				$data->teleported = true;
				if ($data->ticksSinceTeleport === 0) {
					$data->currentMoveDelta = clone PlayerData::$ZERO_VECTOR;
				}
			} else {
				$data->teleported = false;
			}
			++$data->ticksSinceTeleport;
			if ($data->isFlying) {
				$data->ticksSinceFlight = 0;
			} else {
				++$data->ticksSinceFlight;
			}
			++$data->ticksSinceJump;
			if ($data->isGliding) {
				$data->ticksSinceGlide = 0;
			} else {
				++$data->ticksSinceGlide;
			}

			$data->isInVoid = $location->y <= -35;

			$this->lastClientPrediction = $packet->getDelta();
			$data->tick();
		} elseif ($packet instanceof InventoryTransactionPacket) {
			self::$inventoryTransactionTimings->startTiming();
			$trData = $packet->trData;
			if ($trData instanceof UseItemOnEntityTransactionData) {
				$data->lastTarget = $data->target;
				$data->target = $trData->getEntityRuntimeId();
				$data->attackTick = $data->currentTick;
				$data->attackPos = $trData->getPlayerPos();
			} elseif ($trData instanceof UseItemTransactionData) {
				if ($trData->getActionType() === UseItemTransactionData::ACTION_CLICK_BLOCK) {
					$clickedBlockPos = $trData->getBlockPos();
					$newBlockPos = $clickedBlockPos->getSide($trData->getFace());
					$blockToReplace = $data->player->getLevel()->getBlock($newBlockPos, false, false);
					$block = $trData->getItemInHand()->getItemStack()->getBlock();
					$block->position($blockToReplace->asPosition());
					if ($blockToReplace->canBeReplaced() && ($block instanceof UnknownBlock || $block->canBePlaced()) && $data->canPlaceBlocks && $data->boundingBox !== null && !$block->collidesWithBB($data->boundingBox)) {
						if ($trData->getItemInHand()->getItemStack()->getId() < 0) {
							$block = new UnknownBlock($trData->getItemInHand()->getItemStack()->getId(), 0);
							$block->position($blockToReplace->asPosition());
							if ($block->collidesWithBB($data->boundingBox)) {
								return;
							}
						}
						foreach ($this->placedBlocks as $other) {
							if ($other->asVector3()->equals($blockToReplace->asVector3())) {
								return;
							}
						}
						if (($block->canBePlaced() || $block instanceof UnknownBlock)) {
							$block->position($blockToReplace->asPosition());
							$blockAABB = AABB::fromBlock($block);
							if ((!$block instanceof UnknownBlock || $block->isSolid() && !$block->isTransparent()) /* <- so let's talk about that.... */ && $blockAABB->intersectsWith($data->boundingBox->expandedCopy(0.01, 0.01, 0.01))) {
								return;
							}
							$this->placedBlocks[] = clone $block;
						}
					}
				}
			}
			self::$inventoryTransactionTimings->stopTiming();
		} elseif ($packet instanceof NetworkStackLatencyPacket) {
			self::$networkStackLatencyTimings->startTiming();
			NetworkStackLatencyHandler::getInstance()->execute($data, $packet->timestamp);
			self::$networkStackLatencyTimings->stopTiming();
		} elseif ($packet instanceof SetLocalPlayerAsInitializedPacket) {
			$data->loggedIn = true;
			$data->gamemode = $data->player->getGamemode();
		} elseif ($packet instanceof AdventureSettingsPacket) {
			$data->isFlying = $packet->getFlag(AdventureSettingsPacket::FLYING) || $packet->getFlag(AdventureSettingsPacket::NO_CLIP);
		} elseif ($packet instanceof LoginPacket) {
			// ignore modified data other plugins may have put in.. DUM DUM plugins
			$pk = new LoginPacket($packet->getBuffer());
			$pk->decode();
			$data->protocol = $pk->protocol;
			$data->playerOS = $pk->clientData["DeviceOS"];
			$data->isMobile = in_array($pk->clientData["DeviceOS"], [DeviceOS::AMAZON, DeviceOS::ANDROID, DeviceOS::IOS]);
		} elseif ($packet instanceof PacketViolationWarningPacket) {
			Esoteric::getInstance()->loggerThread->write("Violation warning for {$data->player->getName()} || (message={$packet->getMessage()} sev={$packet->getSeverity()} pkID={$packet->getPacketId()})");
		}
		self::$timings->stopTiming();
	}

}

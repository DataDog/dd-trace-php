<?php

namespace DDTrace\Integrations\DiscordPHP;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\Integrations\ReactPromise\ReactPromiseIntegration;
use DDTrace\SpanData;
use DDTrace\SpanStack;
use DDTrace\Tag;
use DDTrace\Type;
use Discord\Builders\Components\Component;
use Discord\Helpers\Multipart;
use Discord\Http\Endpoint;
use Discord\Parts\Channel\Message;
use Discord\Parts\Channel\Overwrite;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\Part;
use Discord\Parts\Permissions\ChannelPermission;
use Discord\Parts\Permissions\Permission;
use Discord\Parts\Thread\Thread;
use Discord\Repository\AbstractRepository;

class DiscordPHPIntegration extends Integration
{
    const NAME = 'discordphp';

    public static function getPartsParsers()
    {
        return [
            \Discord\Parts\Channel\Message::class => function($span, $data) {
                $span->meta["discord.message.id"] = $data->id;
                if (isset($data->author)) {
                    $span->meta["discord.author.id"] = $data->author->id;
                    if ($data->author->bot || $data->author->system) {
                        $span->meta["discord.author.type"] = $data->author->bot ? "bot" : "system";
                    }
                }
                $span->meta["discord.channel.id"] = $data->channel_id;
                $span->meta["discord.guild.id"] = $data->channel->guild_id;
                if ($data->thread) {
                    $span->meta["discord.thread.id"] = $data->thread->id;
                }
                if ($data->type !== \Discord\Parts\Channel\Message::TYPE_DEFAULT) {
                    $span->meta["discord.message.type"] = self::mapMessageType($data->type);
                }
                if (isset($data->referenced_message)) {
                    $span->meta["discord.message.referenced_message_id"] = $data->referenced_message->id;
                }
                if (isset($data->interaction)) {
                    $span->meta["discord.message.interaction.id"] = $data->interaction->id;
                    $span->meta["discord.message.interaction.type"] = self::mapInteractionType($data->interaction->type);
                    $span->meta["discord.message.interaction.name"] = $data->interaction->name;
                    $span->meta["discord.message.interaction.user_id"] = $data->interaction->user->id;
                }
                if ($data->ephemeral) {
                    $span->meta["discord.message.ephemeral"] = "true";
                }
                if (isset($data->nonce)) {
                    $span->meta["discord.message.nonce"] = $data->nonce;
                }
                if (isset($data->application_id)) {
                    $span->meta["discord.message.application_id"] = $data->application_id;
                }
                if ($data->flags) {
                    $span->meta["discord.message.flags"] = self::mapMessageFlags($data->flags);
                }
                $span->meta["discord.message.content_length"] = \strlen($data->content);
            },
            \Discord\Parts\User\User::class => function($span, $data) {
                $span->meta["discord.user.id"] = $data->id;
                if ($data->bot || $data->system) {
                    $span->meta["discord.user.type"] = $data->bot ? "bot" : "system";
                }
            },
            \Discord\Parts\Guild\Guild::class => function($span, $data) {
                $span->meta["discord.guild.id"] = $data->id;
            },
            \Discord\Parts\Channel\Channel::class => function($span, $data) {
                $span->meta["discord.channel.id"] = $data->id;
                $span->meta["discord.guild.id"] = $data->guild_id;
                $span->meta["discord.channel.type"] = self::mapChannelType($data->type);
                if (isset($data->recipient_id)) {
                    $span->meta["discord.channel.recipient_id"] = $data->recipient_id;
                }
                if (isset($data->parent_id)) {
                    $span->meta["discord.channel.parent_id"] = $data->parent_id;
                }
                if ($data->is_private) {
                    $span->meta["discord.channel.private"] = $data->is_private;
                }
            },
            \Discord\Parts\User\Member::class => function($span, $data) {
                $span->meta["discord.user.id"] = $data->id;
                $span->meta["discord.guild.id"] = $data->guild_id;
                $span->meta["discord.user.permissions"] = json_encode(array_keys($data->permissions->getPermissions()));
            },
            \Discord\Parts\Guild\CommandPermissions::class => function($span, $data) {
                $span->meta["discord.application.id"] = $data->application_id;
                $span->meta["discord.command.id"] = $data->id;
                $span->meta["discord.guild.id"] = $data->guild_id;
                $permissions = [];
                foreach ($data->permissions as $permission) {
                    $permissions[self::mapPermissionType($permission->type)][$permission->id] = $permission->permission;
                }
                $span->meta["discord.command.permissions"] = json_encode($permissions);
            },
            \Discord\Parts\WebSockets\AutoModerationActionExecution::class => function($span, $data) {
                $span->meta["discord.guild.id"] = $data->guild_id;
                $span->meta["discord.user.id"] = $data->user_id;
                $span->meta["discord.auto_moderation.rule.id"] = $data->rule_id;
                if (isset($data->channel_id)) {
                    $span->meta["discord.channel.id"] = $data->channel_id;
                }
                $span->meta["discord.auto_moderation.type"] = self::mapRuleTriggerType($data->rule_trigger_type);
                if (isset($data->message_id)) {
                    $span->meta["discord.message.id"] = $data->message_id;
                } elseif (isset($data->alert_system_message_id)) {
                    $span->meta["discord.message.id"] = $data->alert_system_message_id;
                }
                switch ($data->action) {
                    case \Discord\Parts\Guild\AutoModeration\Action::TYPE_BLOCK_MESSAGE:
                        $type = "block_message";
                        break;
                    case \Discord\Parts\Guild\AutoModeration\Action::TYPE_SEND_ALERT_MESSAGE:
                        $type = "send_alert_message";
                        break;
                    case \Discord\Parts\Guild\AutoModeration\Action::TYPE_TIMEOUT:
                        $type = "timeout";
                        break;
                    case \Discord\Parts\Guild\AutoModeration\Action::TYPE_BLOCK_MEMBER_INTERACTION:
                        $type = "block_member_interaction";
                        break;
                    default:
                        $type = $data->action;
                }
                $span->meta["discord.auto_moderation.execution_action"] = $type;
            },
            \Discord\Parts\Guild\AutoModeration\Rule::class => function($span, $data) {
                $span->meta["discord.guild.id"] = $data->guild_id;
                $span->meta["discord.auto_moderation.rule.id"] = $data->id;
                $span->meta["discord.auto_moderation.rule.enabled"] = $data->enabled;
                $span->meta["discord.auto_moderation.type"] = self::mapRuleTriggerType($data->trigger_type);
            },
            \Discord\Parts\Guild\AuditLog\Entry::class => function($span, $data) {
                $span->meta["discord.audit_log.id"] = $data->id;
                $span->meta["discord.audit_log.type"] = self::mapAuditEntryType($data->action_type);
                $span->meta["discord.audit_log.target_id"] = $data->target_id;
            },
            \Discord\Parts\Guild\Ban::class => function($span, $data) {
                $span->meta["discord.guild.id"] = $data->guild_id;
                $span->meta["discord.user.id"] = $data->user_id;
            },
            \Discord\Parts\Guild\ScheduledEvent::class => function($span, $data) {
                $span->meta["discord.guild.id"] = $data->guild_id;
                $span->meta["discord.scheduled_event.id"] = $data->id;
                if ($data->channel_id) {
                    $span->meta["discord.channel.id"] = $data->channel_id;
                }
                $span->meta["discord.scheduled_event.type"] = self::mapScheduledEventStatus($data->status);
            },
            \Discord\Parts\Guild\Sound::class => function($span, $data) {
                $span->meta["discord.guild.id"] = $data->guild_id;
                $span->meta["discord.sound.id"] = $data->sound_id;
                $span->meta["discord.sound.name"] = $data->name;
            },
            \Discord\Parts\Guild\Integration::class => function($span, $data) {
                $span->meta["discord.guild.id"] = $data->guild_id;
                $span->meta["discord.integration.id"] = $data->id;
                $span->meta["discord.integration.name"] = $data->name;
                $span->meta["discord.integration.type"] = $data->type;
                $span->meta["discord.integration.enabled"] = $data->enabled ? "true" : "false";
                if (isset($data->role_id)) {
                    $span->meta["discord.integration.role.id"] = $data->role_id;
                }
                if (isset($data->revoked)) {
                    $span->meta["discord.integration.revoked"] = $data->revoked ? "true" : "false";
                }
            },
            \Discord\Parts\Interactions\Interaction::class => function($span, $data) {
                $span->meta["discord.guild.id"] = $data->guild_id;
                $span->meta["discord.channel.id"] = $data->channel_id;
                $span->meta["discord.interaction.id"] = $data->id;
                $span->meta["discord.interaction.type"] = self::mapInteractionType($data->type);
                if (isset($data->data)) {
                    $span->meta["discord.interaction.name"] = $data->data->name;
                    if (isset($data->data->custom_id)) {
                        $span->meta["discord.interaction.custom_id"] = $data->data->custom_id;
                    }
                    $options = $data->data->options->toArray();
                    $optionNamePrefix = "";
                    $optionNum = 0;
                    foreach ($options as &$option) {
                        if (isset($option->options)) {
                            $optionNamePrefix .= "{$option->name} ";
                            $options = $option->options->toArray();
                            continue;
                        }
                        $span->meta["discord.interaction.option.$optionNum.name"] = $optionNamePrefix . $option->name;
                        if (isset($option->value) && $option->type !== \Discord\Parts\Interactions\Command\Option::STRING) {
                            $span->meta["discord.interaction.option.$optionNum.value"] = $option->value;
                        }
                        ++$optionNum;
                    }
                }
                $span->meta["discord.interaction.continuation_token"] = $data->token;
                if ($data->message) {
                    $span->meta["discord.interaction.message_id"] = $data->message->id;
                }
            },
            \Discord\Parts\Channel\Invite::class => function($span, $data) {
                $span->meta["discord.guild.id"] = $data->guild_id;
                $span->meta["discord.channel.id"] = $data->channel_id;
                if (isset($data->inviter)) {
                    $span->meta["discord.invite.inviter"] = $data->inviter->id;
                }
            },
            \Discord\Parts\WebSockets\PresenceUpdate::class => function($span, $data) {
                $span->meta["discord.guild.id"] = $data->guild_id;
                $span->meta["discord.user.id"] = $data->user->id;
                $span->meta["discord.user.status"] = $data->status;
            },
            \Discord\Parts\Channel\StageInstance::class => function($span, $data) {
                $span->meta["discord.guild.id"] = $data->guild_id;
                $span->meta["discord.channel.id"] = $data->channel_id;
                $span->meta["discord.stage_instance.id"] = $data->id;
                $span->meta["discord.stage_instance.topic"] = $data->topic;
                if (isset($data->guild_scheduled_event_id)) {
                    $span->meta["discord.stage_instance.guild_scheduled_event_id"] = $data->guild_scheduled_event_id;
                }
            },
            \Discord\Parts\Thread\Thread::class => function($span, $data) {
                $span->meta["discord.guild.id"] = $data->guild_id;
                $span->meta["discord.channel.id"] = $data->parent_id;
                $span->meta["discord.thread.id"] = $data->id;
                $span->meta["discord.thread.owner_id"] = $data->owner_id;
                $span->meta["discord.thread.type"] = self::mapChannelType($data->type);
                if ($data->locked) {
                    $span->meta["discord.thread.locked"] = "true";
                }
            },
            \Discord\Parts\WebSockets\TypingStart::class => function($span, $data) {
                $span->meta["discord.guild.id"] = $data->guild_id;
                $span->meta["discord.channel.id"] = $data->channel_id;
                $span->meta["discord.user.id"] = $data->user_id;
            },
            \Discord\Parts\WebSockets\VoiceServerUpdate::class => function($span, $data) {
                $span->meta["discord.guild.id"] = $data->guild_id;
                $span->meta["discord.voice.token"] = $data->token;
            },
            \Discord\Parts\WebSockets\VoiceStateUpdate::class => function($span, $data) {
                if (isset($data->guild_id)) {
                    $span->meta["discord.guild.id"] = $data->guild_id;
                }
                if (isset($data->channel_id)) {
                    $span->meta["discord.channel.id"] = $data->channel_id;
                }
                $span->meta["discord.user.id"] = $data->user_id;
                $span->meta["discord.voice.session_id"] = $data->session_id;
            },
            \Discord\Parts\Guild\Emoji::class => function($span, $data) {
                $span->meta["discord.guild.id"] = $data->guild_id;
                $span->meta["discord.emoji.id"] = $data->id;
                $span->meta["discord.emoji.name"] = $data->name;
            },
            \Discord\Parts\Guild\Role::class => function($span, $data) {
                $span->meta["discord.guild.id"] = $data->guild_id;
                $span->meta["discord.role.id"] = $data->id;
                $span->meta["discord.role.name"] = $data->name;
                $span->meta["discord.role.permissions"] = json_encode(array_keys($data->permissions->getPermissions()));
            },
            \Discord\Parts\Guild\Sticker::class => function($span, $data) {
                $span->meta["discord.guild.id"] = $data->guild_id;
                $span->meta["discord.sticker.id"] = $data->id;
                $span->meta["discord.sticker.name"] = $data->name;
            },
            \Discord\Parts\Thread\Member::class => function($span, $data) {
                $span->meta["discord.guild.id"] = $data->guild_id;
                $span->meta["discord.thread.id"] = $data->id;
                $span->meta["discord.user.id"] = $data->user_id;
            },
        ];
    }

    public static function tagsFromPart($part, $span) {
        if ($part instanceof Part) {
            if ($part instanceof \Discord\Parts\Channel\Channel) {
                $span->meta["discord.guild.id"] = $part->guild_id;
                return true;
            }
            if ($part instanceof \Discord\Parts\Channel\Message) {
                $span->meta["discord.guild.id"] = $part->guild_id;
                $span->meta["discord.channel.id"] = $part->channel_id;
                return true;
            }
            if ($part instanceof \Discord\Parts\Thread\Thread) {
                $span->meta["discord.guild.id"] = $part->guild_id;
                $span->meta["discord.channel.id"] = $part->parent_id;
                $span->meta["discord.thread.id"] = $part->id;
                return true;
            }
            if ($part instanceof \Discord\Parts\Interactions\Interaction) {
                $span->meta["discord.guild.id"] = $part->guild_id;
                $span->meta["discord.channel.id"] = $part->channel_id;
                $span->meta["discord.interaction.id"] = $part->id;
                return true;
            }
        }
        return false;
    }

    public static function collectParentPart($span)
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS | DEBUG_BACKTRACE_PROVIDE_OBJECT, 5) as $frame) {
            if (isset($frame["object"])) {
                if (self::tagsFromPart($frame["object"], $span)) {
                    break;
                }
            }
        }
    }

    public static function getEndpointHandlers()
    {
        return [
            Endpoint::CHANNEL_PERMISSIONS => function($span, $content) {
                self::collectParentPart($span);
                if (\is_array($content)) {
                    $span->meta["discord.permission.type"] = $content["type"] == Overwrite::TYPE_ROLE ? "role" : "member";
                    $span->meta["discord.permission.allow"] = self::mapPermissionBitset($content["allow"]);
                    $span->meta["discord.permission.deny"] = self::mapPermissionBitset($content["id"]);
                }
            },
            Endpoint::GUILD_MEMBERS => function($span, $content) {
                if (\is_array($content)) {
                    if (isset($content["channel_id"])) {
                        $span->meta["discord.voice.channel_id"] = $content["channel_id"];
                    }
                    if (isset($content["mute"])) {
                        $span->meta["discord.voice.mute"] = $content["mute"];
                    }
                    if (isset($content["deaf"])) {
                        $span->meta["discord.voice.deaf"] = $content["deaf"];
                    }
                }
            },
            Endpoint::CHANNEL_INVITES => function($span, $content) {
                self::collectParentPart($span);
                if (\is_array($content)) {
                    if (isset($content["target_user_id"])) {
                        $span->meta["discord.channel_invites.target_user_id"] = $content["target_user_id"];
                    }
                    if (isset($content["target_application_id"])) {
                        $span->meta["discord.channel_invites.target_application_id"] = $content["target_application_id"];
                    }
                    if (!empty($content["temporary"])) {
                        $span->meta["discord.channel_invites.temporary"] = "true";
                    }
                }
            },
            Endpoint::CHANNEL_MESSAGES_BULK_DELETE => function($span, $content) {
                self::collectParentPart($span);
                if (\is_array($content)) {
                    $i = 0;
                    foreach ($content["messages"] as $message) {
                        $span->meta["discord.message.id." . $i++] = $message;
                    }
                }
            },
            Endpoint::CHANNEL_PIN => function($span, $content) {
                self::collectParentPart($span);
            },
            Endpoint::CHANNEL_THREADS => function($span, $content) {
                self::collectParentPart($span);
                $content = self::collectMessageMultipart($span, $content);
                if (!empty($content["private"])) {
                    $span->meta["discord.thread.private"] = "true";
                }
                if (isset($content["locked"])) {
                    $span->meta["discord.thread.locked"] = $content["locked"] ? "true" : "false";
                }
                if (!isset($content["invitable"])) {
                    $span->meta["discord.thread.invitable"] = $content["invitable"] ? "true" : "false";
                }
                if (!empty($content["name"])) {
                    $span->meta["discord.thread.name"] = $content["name"];
                }
                if (isset($content["auto_archive_duration"])) {
                    $span->meta["discord.thread.auto_archive_duration"] = $content["auto_archive_duration"];
                }
                if (!empty($content["rate_limit_per_user"])) {
                    $span->meta["discord.thread.rate_limit_per_user"] = $content["rate_limit_per_user"];
                }
                if (!empty($content["applied_tags"])) {
                    foreach ($content["applied_tags"] as $i => $tag) {
                        $span->meta["discord.thread.applied_tags.$i"] = $tag;
                    }
                }
                if (isset($content["flags"]) && ($content["flags"] & Thread::FLAG_PINNED)) {
                    $span->meta["discord.thread.pinned"] = "true";
                }
            },
            Endpoint::CHANNEL_MESSAGES => function($span, $content) {
                self::collectParentPart($span);
                self::collectMessageMultipart($span, $content);
            },
            Endpoint::CHANNEL_TYPING => function($span, $content) {
                self::collectParentPart($span);
            },
            Endpoint::CHANNEL_MESSAGE_THREADS => function($span, $content) {
                self::collectParentPart($span);
                if (\is_array($content)) {
                    if (!empty($content["name"])) {
                        $span->meta["discord.thread.name"] = $content["name"];
                    }
                    if (isset($content["auto_archive_duration"])) {
                        $span->meta["discord.thread.auto_archive_duration"] = $content["auto_archive_duration"];
                    }
                    if (!empty($content["rate_limit_per_user"])) {
                        $span->meta["discord.thread.rate_limit_per_user"] = $content["rate_limit_per_user"];
                    }
                }
            },
            Endpoint::CHANNEL_CROSSPOST_MESSAGE => function($span, $content) {
                self::collectParentPart($span);
            },
            Endpoint::OWN_MESSAGE_REACTION => function($span, $content) {
                self::collectParentPart($span);
                if (\is_string($content)) {
                    $span->meta["discord.message.reaction"] = $content;
                }
            },
            Endpoint::CHANNEL_MESSAGE => function($span, $content) {
                self::collectParentPart($span);
                self::collectMessageMultipart($span, $content);
            },
            Endpoint::MESSAGE_POLL_EXPIRE => function($span, $content) {},
            Endpoint::WEBHOOK_EXECUTE => function($span, $content) {
                self::collectMessageMultipart($span, $content);
            },
            Endpoint::WEBHOOK_MESSAGE => function($span, $content) {
                if (isset($content["content"])) {
                    $span->meta["discord.message.content_length"] = \strlen($content["content"]);
                }
                if (isset($content["flags"])) {
                    $span->meta["discord.message.flags"] = self::mapMessageFlags($content["flags"]);
                }
            },
            Endpoint::CHANNEL_WEBHOOKS => $createWebhook = function($span, $content) {
                if (\is_array($content)) {
                    if (isset($content["name"])) {
                        $span->meta["discord.webhook.name"] = $content["name"];
                    }
                    if (isset($content["avatar"])) {
                        $span->meta["discord.webhook.avatar"] = $content["avatar"];
                    }
                    if (isset($content["channel_id"])) {
                        $span->meta["discord.webhook.channel_id"] = $content["channel_id"];
                    }
                }
            },
            Endpoint::WEBHOOK => $createWebhook,
            Endpoint::GUILD_EMOJIS => $guildEmojis = function($span, $content) {
                if (\is_array($content)) {
                    if (isset($content["name"])) {
                        $span->meta["discord.emoji.name"] = $content["name"];
                    }
                    if (isset($content["image"])) {
                        $span->meta["discord.emoji.image_length"] = \strlen($content["image"]);
                    }
                    if (isset($content["roles"])) {
                        foreach ($content["roles"] as $i => $role) {
                            $span->meta["discord.emoji.roles.$i"] = $role;
                        }
                    }
                }
            },
            Endpoint::GUILD_EMOJI => $guildEmojis,
            Endpoint::GUILD_STICKERS => function($span, $content) {
                if ($content instanceof Multipart) {
                    $fields = (function () {
                        return $this->fields;
                    })->call($content);
                    foreach ($fields as $field) {
                        if (isset($field["name"])) {
                            $name = $field["name"];
                            if ($name === "name") {
                                $span->meta["discord.sticker.name"] = $field["content"];
                            } elseif ($name === "description") {
                                $span->meta["discord.sticker.description_length"] = \strlen($field["content"]);
                            } elseif ($name === "tags") {
                                $span->meta["discord.sticker.tags_length"] = count($field["tags"]);
                            } elseif ($name === "file") {
                                $span->meta["discord.sticker.file_length"] = \strlen($field["content"]);
                                $span->meta["discord.sticker.file_name"] = $field["filename"];
                            }
                        }
                    }
                }
            },
            Endpoint::GUILD_STICKER => function($span, $content) {
                if (\is_array($content)) {
                    if (isset($content["name"])) {
                        $span->meta["discord.sticker.name"] = $content["name"];
                    }
                    if (isset($content["description"])) {
                        $span->meta["discord.sticker.description_length"] = \strlen($content["description"]);
                    }
                    if (isset($content["tags"])) {
                        $span->meta["discord.sticker.tags_length"] = count($content["tags"]);
                    }
                }
            },
            Endpoint::GUILDS => $createGuild = function($span, $content) {
                if (\is_array($content)) {
                    if (isset($content["owner_id"])) {
                        $span->meta["discord.guild.owner_id"] = $content["owner_id"];
                    }
                    if (isset($content["features"])) {
                        $span->meta["discord.guild.features"] = json_encode($content["features"]);
                    }
                    if (isset($content["name"])) {
                        $span->meta["discord.guild.name"] = $content["name"];
                    }
                    if (isset($content["description"])) {
                        $span->meta["discord.guild.description_length"] = strlen($content["description"]);
                    }
                    if (isset($content["icon"])) {
                        $span->meta["discord.guild.icon"] = $content["icon"];
                    }
                    if (isset($content["splash"])) {
                        $span->meta["discord.guild.splash"] = $content["splash"];
                    }
                    if (isset($content["discovery_splash"])) {
                        $span->meta["discord.guild.discovery_splash"] = $content["discovery_splash"];
                    }
                    if (isset($content["banner"])) {
                        $span->meta["discord.guild.banner"] = $content["banner"];
                    }
                    if (isset($content["region"])) {
                        $span->meta["discord.guild.region"] = $content["region"];
                    }
                    if (isset($content["afk_channel_id"])) {
                        $span->meta["discord.guild.afk_channel_id"] = $content["afk_channel_id"];
                    }
                    if (isset($content["afk_timeout"])) {
                        $span->meta["discord.guild.afk_timeout"] = $content["afk_timeout"];
                    }
                    if (isset($content["system_channel_id"])) {
                        $span->meta["discord.guild.system_channel_id"] = $content["system_channel_id"];
                    }
                    if (isset($content["system_channel_flags"])) {
                        $span->meta["discord.guild.system_channel_flags"] = self::mapSystemChannelFlags($content["system_channel_flags"]);
                    }
                    if (isset($content["verification_level"])) {
                        $span->meta["discord.guild.verification_level"] = $content["verification_level"];
                    }
                    if (isset($content["default_message_notifications"])) {
                        $span->meta["discord.guild.default_message_notifications"] = $content["default_message_notifications"];
                    }
                    if (isset($content["explicit_content_filter"])) {
                        $span->meta["discord.guild.explicit_content_filter"] = $content["explicit_content_filter"];
                    }
                    if (isset($content["public_updates_channel_id"])) {
                        $span->meta["discord.guild.public_updates_channel_id"] = $content["public_updates_channel_id"];
                    }
                    if (isset($content["rules_channel_id"])) {
                        $span->meta["discord.guild.rules_channel_id"] = $content["rules_channel_id"];
                    }
                    if (isset($content["safety_alerts_channel_id"])) {
                        $span->meta["discord.guild.safety_alerts_channel_id"] = $content["safety_alerts_channel_id"];
                    }
                    if (isset($content["preferred_locale"])) {
                        $span->meta["discord.guild.preferred_locale"] = $content["preferred_locale"];
                    }
                    if (isset($content["premium_progress_bar_enabled"])) {
                        $span->meta["discord.guild.premium_progress_bar_enabled"] = $content["premium_progress_bar_enabled"] ? "true" : "false";
                    }
                }
            },
            Endpoint::GUILD => $createGuild,
            Endpoint::GUILD_ROLE => $createGuildRole = function($span, $content) {
                if (\is_array($content)) {
                    if (isset($content["name"])) {
                        $span->meta["discord.role.name"] = $content["name"];
                    }
                    if (isset($content["color"])) {
                        $span->meta["discord.role.color"] = $content["color"];
                    }
                    if (isset($content["hoist"])) {
                        $span->meta["discord.role.hoist"] = $content["hoist"] ? "true" : "false";
                    }
                    if (isset($content["permissions"])) {
                        $span->meta["discord.role.permissions"] = self::mapPermissionBitset($content["permissions"]);
                    }
                    if (isset($content["icon"])) {
                        $span->meta["discord.role.icon"] = $content["icon"];
                    }
                    if (isset($content["unicode_emoji"])) {
                        $span->meta["discord.role.unicode_emoji"] = $content["unicode_emoji"];
                    }
                    if (isset($content["mentionable"])) {
                        $span->meta["discord.role.mentionable"] = $content["mentionable"] ? "true" : "false";
                    }
                }
            },
            Endpoint::GUILD_ROLES => function($span, $content) use ($createGuildRole) {
                if (\is_array($content)) {
                    if (\is_array(current($content))) {
                        foreach ($content as $role) {
                            if (isset($role["id"], $role["position"])) {
                                $span->meta["discord.role.{$role["position"]}"] = $role["id"];
                            }
                        }
                    } else {
                        $createGuildRole($span, $content);
                    }
                }
            },
            Endpoint::GUILD_WELCOME_SCREEN => function($span, $content) {
                if (\is_array($content)) {
                    if (isset($content["enabled"])) {
                        $span->meta["discord.guild.welcome_screen.enabled"] = $content["enabled"] ? "true" : "false";
                    }
                    if (isset($content["welcome_channels"])) {
                        foreach ($content["welcome_channels"] as $i => $welcome_channel) {
                            $span->meta["discord.guild.welcome_screen.channel_id.$i"] = $welcome_channel;
                        }
                    }
                    if (isset($content["description"])) {
                        $span->meta["discord.guild.welcome_screen.description_length"] = \strlen($content["description"]);
                    }
                }
            },
            Endpoint::GUILD_WIDGET_SETTINGS => function($span, $content) {
                if (\is_array($content)) {
                    if (isset($content["enabled"])) {
                        $span->meta["discord.guild.widget.enabled"] = $content["enabled"] ? "true" : "false";
                    }
                    if (isset($content["description"])) {
                        $span->meta["discord.guild.widget.channel_id"] = $content["channel_id"];
                    }
                }
            },
            Endpoint::GUILD_MFA => function($span, $content) {
                if (\is_array($content)) {
                    if (isset($content["level"])) {
                        $span->meta["discord.guild.mfa_level"] = $content["level"];
                    }
                }
            },
            Endpoint::GUILDS_TEMPLATE => $createGuildsTemplate = function($span, $content) {
                if (\is_array($content)) {
                    if (isset($content["name"])) {
                        $span->meta["discord.guils.name"] = $content["name"];
                    }
                    if (isset($content["description"])) {
                        $span->meta["discord.guils.description_length"] = strlen($content["description"]);
                    }
                }
            },
            Endpoint::GUILD_TEMPLATE => $createGuildsTemplate,
            Endpoint::ORIGINAL_INTERACTION_RESPONSE => function($span, $content) {
                self::collectParentPart($span);
                self::collectMessageMultipart($span, $content);
            },
            Endpoint::CREATE_INTERACTION_FOLLOW_UP => function($span, $content) {
                self::collectParentPart($span);
                self::collectMessageMultipart($span, $content);
            },
            Endpoint::INTERACTION_FOLLOW_UP => function($span, $content) {
                self::collectParentPart($span);
                self::collectMessageMultipart($span, $content);
            },
            Endpoint::INTERACTION_RESPONSE => function($span, $content) {
                self::collectParentPart($span);
                $content = self::collectMessageMultipart($span, $content);
                $span->meta["discord.interaction.response_type"] = self::mapInteractionResponseType($content["type"]);
                switch ($content["type"] ?? 0) {
                    case Interaction::RESPONSE_TYPE_DEFERRED_CHANNEL_MESSAGE_WITH_SOURCE:
                        if (($content["data"]["flags"] ?? 0) == Message::FLAG_EPHEMERAL) {
                            $span->meta["discord.interaction.ephemeral"] = "true";
                        }
                        break;
                    case Interaction::RESPONSE_TYPE_APPLICATION_COMMAND_AUTOCOMPLETE_RESULT:
                        if (isset($content["data"]["choices"])) {
                            $span->meta["discord.interaction.autocomplete_suggestions"] = count($content["data"]["choices"]);
                        }
                        break;
                    case Interaction::RESPONSE_TYPE_MODAL:
                        if (isset($content["data"]["components"])) {
                            self::collectComponents($span, "discord.interaction.components", $content["data"]["components"]);
                        }
                        if (isset($content["data"]["custom_id"])) {
                            $span->meta["discord.interaction.custom_id"] = $content["data"]["custom_id"];
                        }
                        if (isset($content["data"]["title"])) {
                            $span->meta["discord.interaction.modal_title"] = $content["data"]["title"];
                        }
                        break;
                }
            },
            Endpoint::THREAD_MEMBER_ME => function($span, $content) {
                self::collectParentPart($span);
            },
            Endpoint::THREAD_MEMBER => function($span, $content) {
                self::collectParentPart($span);
            },
            Endpoint::THREAD_MEMBER => function($span, $content) {
                self::collectParentPart($span);
                if (\is_array($content)) {
                    if (isset($content["name"])) {
                        $span->meta["discord.thread.name"] = $content["name"];
                    }
                    if (isset($content["archived"])) {
                        $span->meta["discord.thread.archived"] = $content["archived"] ? "true" : "false";
                    }
                    if (isset($content["auto_archive_duration"])) {
                        $span->meta["discord.thread.auto_archive_duration"] = $content["auto_archive_duration"];
                    }
                }
            },
            Endpoint::USER_CURRENT => function($span, $content) {
                if (\is_array($content)) {
                    if (isset($content["username"])) {
                        $span->meta["discord.user.name"] = $content["username"];
                    }
                }
            },
            Endpoint::GUILD_MEMBER_SELF => function($span, $content) {
                if (\is_array($content)) {
                    if (isset($content["nick"])) {
                        $span->meta["discord.user.name"] = $content["nick"];
                    }
                }
            },
            Endpoint::GUILD_MEMBER => function($span, $content) {
                if (\is_array($content)) {
                    if (isset($content["nick"])) {
                        $span->meta["discord.user.name"] = $content["nick"];
                    }
                    if (isset($content["flags"])) {
                        $span->meta["discord.user.flags"] = self::mapMemberFlags($content["flags"]);
                    }
                    if (isset($content["channel_id"])) {
                        $span->meta["discord.voice.channel_id"] = $content["channel_id"];
                    }
                    if (isset($content["communication_disabled_until"])) {
                        $span->meta["discord.user.communication_disabled_until"] = $content["communication_disabled_until"];
                    }
                    if (isset($content["roles"])) {
                        foreach ($content["roles"] as $i => $role) {
                            $span->meta["discord.user.roles.$i"] = $role;
                        }
                    }
                }
            },
            Endpoint::GUILD_MEMBER_ROLE => function($span, $content) {},
            Endpoint::USER_CURRENT_CHANNELS => function($span, $content) {},
            Endpoint::GUILD_BAN => function($span, $content) {
                if (\is_array($content)) {
                    if (isset($content["delete_message_seconds"])) {
                        $span->meta["discord.ban.delete_message_seconds"] = $content["delete_message_seconds"];
                    } elseif (isset($content["delete_message_days"])) {
                        $span->meta["discord.ban.delete_message_seconds"] = $content["delete_message_days"] * 86400;
                    }
                }
            },
            Endpoint::GUILD_BANS => function($span, $content) {},
            Endpoint::GUILD_CHANNELS => $guildChannels = function($span, $content) {
                if (\is_array($content)) {
                    if (isset($content["name"])) {
                        $span->meta["discord.channel.name"] = $content["name"];
                    }
                    if (isset($content["type"])) {
                        $span->meta["discord.channel.type"] = self::mapChannelType($content["type"]);
                    }
                    if (isset($content["topic"])) {
                        $span->meta["discord.channel.topic"] = $content["topic"];
                    }
                    if (isset($content["parent_id"])) {
                        $span->meta["discord.channel.parent_id"] = $content["parent_id"];
                    }
                    if (isset($content["nsfw"])) {
                        $span->meta["discord.channel.nsfw"] = $content["nsfw"] ? "true" : "false";
                    }
                    if (isset($content["rate_limit_per_user"])) {
                        $span->meta["discord.channel.rate_limit_per_user"] = $content["rate_limit_per_user"];
                    }
                    if (isset($content["position"])) {
                        $span->meta["discord.channel.position"] = $content["position"];
                    }
                    if (isset($content["auto_archive_duration"])) {
                        $span->meta["discord.channel.auto_archive_duration"] = $content["auto_archive_duration"];
                    }
                    if (isset($content["permission_overwrites"])) {
                        foreach ($content["permission_overwrites"] as $i => $overwrite) {
                            $span->meta["discord.channel.permission_overwrites.$i.id"] = $overwrite["id"];
                            $span->meta["discord.channel.permission_overwrites.$i.allow"] = self::mapPermissionBitset($overwrite["allow"]);
                            $span->meta["discord.channel.permission_overwrites.$i.deny"] = self::mapPermissionBitset($overwrite["id"]);
                            $span->meta["discord.channel.permission_overwrites.$i.type"] = $content["type"] == Overwrite::TYPE_ROLE ? "role" : "member";
                        }
                    }
                }
            },
            Endpoint::CHANNEL => function ($span, $content) use ($guildChannels) {
                self::collectParentPart($span);
                $guildChannels($span, $content);
            },
            Endpoint::GUILD_AUTO_MODERATION_RULES => $createAutomodRules = function ($span, $content) {
                if (\is_array($content)) {
                    if (isset($content["name"])) {
                        $span->meta["discord.auto_moderation.rule.name"] = $content["name"];
                    }
                    if (isset($content["event_type"])) {
                        $span->meta["discord.auto_moderation.rule.event_type"] = self::mapRuleEventType($content["event_type"]);
                    }
                    if (isset($content["trigger_type"])) {
                        $span->meta["discord.auto_moderation.rule.trigger_type"] = self::mapRuleTriggerType($content["trigger_type"]);
                    }
                    if (isset($content["actions"])) {
                        foreach ($content["actions"] as $i => $action) {
                            $span->meta["discord.auto_moderation.rule.actions.$i.type"] = self::mapRuleActionType($action["type"]);
                            if (isset($action["metadata"]["channel_id"])) {
                                $span->meta["discord.auto_moderation.rule.actions.$i.channel_id"] = $action["metadata"]["channel_id"];
                            }
                            if (isset($action["metadata"]["duration_seconds"])) {
                                $span->meta["discord.auto_moderation.rule.actions.$i.duration_seconds"] = $action["metadata"]["duration_seconds"];
                            }
                            if (isset($action["metadata"]["custom_message"])) {
                                $span->meta["discord.auto_moderation.rule.actions.$i.custom_message_length"] = strlen($action["metadata"]["custom_message"]);
                            }
                        }
                    }
                    $span->meta["discord.auto_moderation.rule.enabled"] = !empty($content["enabled"]) ? "true" : "false";
                    if (isset($content["trigger_metadata"])) {
                        if (isset($content["trigger_metadata"]["keyword_filter"])) {
                            $span->meta["discord.auto_moderation.rule.trigger_metadata.keyword_filter_length"] = count($content["trigger_metadata"]["keyword_filter"]);
                        }
                        if (isset($content["trigger_metadata"]["regex_patterns"])) {
                            $span->meta["discord.auto_moderation.rule.trigger_metadata.regex_patterns_length"] = count($content["trigger_metadata"]["regex_patterns"]);
                        }
                        if (isset($content["trigger_metadata"]["presets"])) {
                            foreach ($content["trigger_metadata"]["presets"] as $i => $preset) {
                                $span->meta["discord.auto_moderation.rule.trigger_metadata.presets.$i"] = self::mapAutomoderationPreset($preset);
                            }
                        }
                        if (isset($content["trigger_metadata"]["allow_list"])) {
                            $span->meta["discord.auto_moderation.rule.trigger_metadata.allow_list_length"] = count($content["trigger_metadata"]["allow_list"]);
                        }
                        if (isset($content["trigger_metadata"]["mention_total_limit"])) {
                            $span->meta["discord.auto_moderation.rule.trigger_metadata.mention_total_limit"] = $content["trigger_metadata"]["mention_total_limit"];
                        }
                        if (isset($content["trigger_metadata"]["mention_raid_protection_enabled"])) {
                            $span->meta["discord.auto_moderation.rule.trigger_metadata.mention_raid_protection_enabled"] = $content["trigger_metadata"]["mention_raid_protection_enabled"] ? "true" : "false";
                        }
                    }
                }
            },
            Endpoint::GUILD_AUTO_MODERATION_RULE => $createAutomodRules,
            Endpoint::GUILD_APPLICATION_COMMANDS_PERMISSIONS => function ($span, $content) {
                if (\is_array($content)) {
                    if (isset($content["permissions"])) {
                        foreach ($content["permissions"] as $i => $permission) {
                            $span->meta["discord.application_command_permissions.$i.id"] = $permission["id"];
                            $span->meta["discord.application_command_permissions.$i.type"] = self::mapPermissionType($permission["type"]);
                            $span->meta["discord.application_command_permissions.$i.permission"] = $permission["permission"] ? "true" : "false";
                        }
                    }
                }
            },
            Endpoint::GUILD_APPLICATION_COMMANDS => $createApplicationCommand = function ($span, $content) {
                if (\is_array($content)) {
                    if (isset($content["name"])) {
                        $span->meta["discord.application_command.name"] = $content["name"];
                    }
                    if (isset($content["name_localizations"])) {
                        $span->meta["discord.application_command.name_localizations_length"] = count($content["name_localizations"]);
                    }
                    if (isset($content["description"])) {
                        $span->meta["discord.application_command.description_length"] = strlen($content["description"]);
                    }
                    if (isset($content["description_localizations"])) {
                        $span->meta["discord.application_command.description_localizations_length"] = count($content["description_localizations"]);
                    }
                    if (isset($content["options"])) {
                        $span->meta["discord.application_command.options_length"] = count($content["options"]);
                    }
                    if (isset($content["type"])) {
                        $span->meta["discord.application_command.type"] = self::mapApplicationCommandType($content["type"]);
                    }
                }
            },
            Endpoint::GUILD_APPLICATION_COMMAND => $createApplicationCommand,
            Endpoint::GLOBAL_APPLICATION_COMMANDS => $createApplicationCommand,
            Endpoint::GLOBAL_APPLICATION_COMMAND => $createApplicationCommand,
            Endpoint::GUILD_SCHEDULED_EVENTS => $createScheduledEvent = function ($span, $content) {
                if (\is_array($content)) {
                    if (isset($content["entity_metadata"]["location"])) {
                        $span->meta["discord.scheduled_event.entity_metadata.location"] = $content["entity_metadata"]["location"];
                    }
                    if (isset($content["scheduled_start_time"])) {
                        $span->meta["discord.scheduled_event.scheduled_start_time"] = $content["scheduled_start_time"];
                    }
                    if (isset($content["scheduled_end_time"])) {
                        $span->meta["discord.scheduled_event.scheduled_end_time"] = $content["scheduled_end_time"];
                    }
                    if (isset($content["image"])) {
                        $span->meta["discord.scheduled_event.image"] = $content["image"];
                    }
                    if (isset($content["channel_id"])) {
                        $span->meta["discord.scheduled_event.channel_id"] = $content["channel_id"];
                    }
                    if (isset($content["description"])) {
                        $span->meta["discord.scheduled_event.description_length"] = strlen($content["description"]);
                    }
                    if (isset($content["entity_type"])) {
                        $span->meta["discord.scheduled_event.entity_type"] = self::mapScheduledEventEntityType($content["entity_type"]);
                    }
                    if (isset($content["status"])) {
                        $span->meta["discord.scheduled_event.status"] = self::mapScheduledEventStatus($content["status"]);
                    }

                }
            },
            Endpoint::GUILD_SCHEDULED_EVENT => $createScheduledEvent,
            Endpoint::GUILD_SOUNDBOARD_SOUNDS => $createSound = function ($span, $content) {
                if (\is_array($content)) {
                    if (isset($content["name"])) {
                        $span->meta["discord.sound.name"] = $content["name"];
                    }
                    if (isset($content["description"])) {
                        $span->meta["discord.sound.description_length"] = strlen($content["description"]);
                    }
                    if (isset($content["volume"])) {
                        $span->meta["discord.sound.volume"] = $content["volume"];
                    }
                    if (isset($content["emoji_id"])) {
                        $span->meta["discord.sound.emoji_id"] = $content["emoji_id"];
                    }
                    if (isset($content["emoji_name"])) {
                        $span->meta["discord.sound.emoji_name"] = $content["emoji_name"];
                    }
                    if (isset($content["sound"])) {
                        $span->meta["discord.sound.size"] = strlen($content["sound"]);
                    }
                }
            },
            Endpoint::GUILD_SOUNDBOARD_SOUND => $createSound,
            Endpoint::STAGE_INSTANCES => $createStageInstance = function ($span, $content) {
                if (\is_array($content)) {
                    if (isset($content["topic"])) {
                        $span->meta["discord.stage_instance.topic"] = $content["topic"];
                    }
                    if (isset($content["channel_id"])) {
                        $span->meta["discord.stage_instance.channel_id"] = $content["channel_id"];
                    }
                    if (isset($content["guild_scheduled_event_id"])) {
                        $span->meta["discord.stage_instance.guild_scheduled_event_id"] = $content["guild_scheduled_event_id"];
                    }
                    if (isset($content["send_start_notification"])) {
                        $span->meta["discord.stage_instance.send_start_notification"] = $content["send_start_notification"] ? "true" : "false";
                    }
                }
            },
            Endpoint::STAGE_INSTANCE => $createStageInstance,
            Endpoint::APPLICATION_EMOJIS => $createEmoji = function ($span, $content) {
                if (\is_array($content)) {
                    if (isset($content["name"])) {
                        $span->meta["discord.emoji.name"] = $content["name"];
                    }
                    if (isset($content["roles"])) {
                        $span->meta["discord.emoji.roles"] = implode(",", $content["roles"]);
                    }
                }
            },
            Endpoint::APPLICATION_EMOJI => $createEmoji,
        ];
    }

    public static function collectMessageMultipart($span, $content)
    {
        if ($content instanceof Multipart) {
            $fields = (function () {
                return $this->fields;
            })->call($content);
            foreach ($fields as $field) {
                if (isset($field["name"])) {
                    $name = $field["name"];
                    if ($name === "payload_json") {
                        $content = json_decode($field["content"], true);
                    } elseif (isset($field["filename"])) {
                        $span->meta["discord.message." . $name] = $field["filename"];
                    }
                }
            }
        }
        $message = $content;
        if (!\is_array($message)) {
            return $content;
        }
        if (isset($message["message"])) {
            $message = $message["message"];
        } elseif (isset($message["data"])) {
            if (isset($message["type"]) && $message["type"] != Interaction::RESPONSE_TYPE_UPDATE_MESSAGE && $message["type"] != Interaction::RESPONSE_TYPE_CHANNEL_MESSAGE_WITH_SOURCE) {
                return $content;
            }
            $message = $message["data"];
        }
        if (isset($message["attachments"])) {
            $i = 0;
            foreach ($message["attachments"] as $attachment) {
                if (isset($attachment["id"])) {
                    $span->meta["discord.message.attachment.$i.id"] = $attachment["id"];
                }
                if (isset($attachment["filename"])) {
                    $span->meta["discord.message.attachment.$i.id"] = $attachment["filename"];
                }
                if (isset($attachment["url"])) {
                    $span->meta["discord.message.attachment.$i.url"] = $attachment["url"];
                }
                if (isset($attachment["size"])) {
                    $span->meta["discord.message.attachment.$i.size"] = $attachment["size"];
                }
                if (isset($attachment["content_type"])) {
                    $span->meta["discord.message.attachment.$i.content_type"] = $attachment["content_type"];
                }
                if (isset($attachment["description"])) {
                    $span->meta["discord.message.attachment.$i.description_length"] = \strlen($attachment["description"]);
                }
                ++$i;
            }
        }
        if (isset($message["embeds"])) {
            $i = 0;
            foreach ($message["embeds"] as $embed) {
                if (isset($embed["image"]["url"])) {
                    $span->meta["discord.message.embed.$i.image"] = $embed["image"]["url"];
                }
                if (isset($embed["video"]["url"])) {
                    $span->meta["discord.message.embed.$i.video"] = $embed["image"]["url"];
                }
                if (isset($embed["image"]["title"])) {
                    $span->meta["discord.message.embed.$i.title"] = $embed["image"]["title"];
                }
                if (isset($embed["image"]["description"])) {
                    $span->meta["discord.message.embed.$i.description_length"] = \strlen($embed["image"]["description"]);
                }
                if (!empty($embed["fields"])) {
                    $j = 0;
                    foreach ($embed["fields"] as $field) {
                        $span->meta["discord.message.embed.$i.field.$j.name"] = $field["name"];
                        $span->meta["discord.message.embed.$i.field.$j.value_length"] = \strlen($field["value"]);
                        ++$j;
                    }
                }
                ++$i;
            }
        }
        if (isset($message["components"])) {
            self::collectComponents($span, "discord.message.components", $message["components"]);
        }
        if (isset($message["content"])) {
            $span->meta["discord.message.content_length"] = \strlen($message["content"]);
        }
        if (isset($message["nonce"])) {
            $span->meta["discord.message.nonce"] = \strlen($message["nonce"]);
        }
        if (isset($message["message_reference"])) {
            $type = ($message["message_reference"]["type"] ?? 0) == Message::REFERENCE_FORWARD ? "forward" : "reply";
            $span->meta["discord.message.$type.message_id"] = $message["message_reference"]["message_id"];
            $span->meta["discord.message.$type.channel_id"] = $message["message_reference"]["channel_id"];
        }
        if (isset($message["poll"])) {
            $span->meta["discord.message.poll.num_options"] = count($message["poll"]["answers"]);
            $span->meta["discord.message.poll.multiselect"] = empty($message["poll"]["allow_multiselect"]) ? "false" : "true";
        }
        if (isset($message["flags"])) {
            $span->meta["discord.message.flags"] = self::mapMessageFlags($message["flags"]);
        }
        if (!empty($message["enforce_nonce"])) {
            $span->meta["discord.message.enforce_nonce"] = "true";
        }
        if (!empty($message["sticker_ids"])) {
            $i = 0;
            foreach ($message["sticker_ids"] as $sticker_id) {
                $span->meta["discord.message.sticker_id.$i"] = $sticker_id;
                ++$i;
            }
        }
        return $content;
    }

    private static function collectComponents($span, $prefix, $components) {
        $i = 0;
        foreach ($components as $component) {
            switch ($component["type"] ?? -1) {
                case Component::TYPE_ACTION_ROW:
                    self::collectComponents($span, "$prefix.$i.action_row", $component["components"] ?? []);
                    break;
                case Component::TYPE_BUTTON:
                    $span->meta["$prefix.style"] = self::mapButtonStyle($component["style"] ?? 0);
                    if (isset($component["label"])) {
                        $span->meta["$prefix.label"] = $component["label"];
                    }
                    if (isset($component["url"])) {
                        $span->meta["$prefix.url"] = $component["url"];
                    }
                    break;
                case Component::TYPE_STRING_SELECT:
                case Component::TYPE_USER_SELECT:
                case Component::TYPE_ROLE_SELECT:
                case Component::TYPE_MENTIONABLE_SELECT:
                case Component::TYPE_CHANNEL_SELECT:
                    if (isset($component["placeholder"])) {
                        $span->meta["$prefix.placeholder"] = $component["placeholder"];
                    }
                    if (isset($component["min_values"])) {
                        $span->meta["$prefix.min_values"] = $component["min_values"];
                    }
                    if (isset($component["max_values"])) {
                        $span->meta["$prefix.max_values"] = $component["max_values"];
                    }
                    if (isset($component["options"])) {
                        $j = 0;
                        foreach ($component["options"] as $option) {
                            $span->meta["$prefix.option.$j.label"] = $option["label"];
                            $span->meta["$prefix.option.$j.value"] = $option["value"];
                            $span->meta["$prefix.option.$j.description_length"] = \strlen($option["description"]);
                            if (!empty($option["default"])) {
                                $span->meta["$prefix.option.$j.default"] = "true";
                            }
                            ++$j;
                        }
                    }
                    break;
                case Component::TYPE_TEXT_INPUT:
                    if (isset($component["placeholder"])) {
                        $span->meta["$prefix.placeholder"] = $component["placeholder"];
                    }
                    if (isset($component["min_length"])) {
                        $span->meta["$prefix.min_length"] = $component["min_length"];
                    }
                    if (isset($component["max_length"])) {
                        $span->meta["$prefix.max_length"] = $component["max_length"];
                    }
                    if (!empty($option["required"])) {
                        $span->meta["$prefix.option.$j.required"] = "true";
                    }
                    break;
            }
            if (isset($component["custom_id"])) {
                $span->meta["$prefix.custom_id"] = $component["custom_id"];
            }
            if (!empty($component["disabled"])) {
                $span->meta["$prefix.disabled"] = "true";
            }
            ++$i;
        }

    }

    /**
     * {@inheritdoc}
     */
    public function requiresExplicitTraceAnalyticsEnabling(): bool
    {
        return false;
    }

    public function init(): int
    {
        $integration = $this;

        ini_set("datadog.trace.websocket_messages_enabled", "1");

        \DDTrace\install_hook(
            'Discord\Discord::handleDispatch',
            function (HookData $hook) {
                $span = \DDTrace\active_span();
                $data = $hook->args[0];
                $span->resource = $data->t;
                $span->name = "discord.receive";
            }
        );

        $partsParsers = self::getPartsParsers();
        \DDTrace\install_hook('Discord\Discord::on', function (HookData $hook) use ($partsParsers) {
            $event = $hook->args[0];
            $listener = $hook->args[1];
            \DDTrace\install_hook($listener, function (HookData $hook) use ($partsParsers, $event) {
                $span = $hook->span();
                $span->resource = $event;
                $span->name = "discord.handle";

                foreach (array_reverse($hook->args) as $data) {
                    if (\is_object($data) && $parser = $partsParsers[get_class($data)] ?? null) {
                        $parser($span, $data);
                    }
                }
            }, null, \DDTrace\HOOK_INSTANCE);
        });

        $endpointHandlers = self::getEndpointHandlers();
        ReactPromiseIntegration::tracePromiseFunction('Discord\Http\Http::queueRequest', function (HookData $hook, SpanData $span) use ($endpointHandlers, &$lastMultipart, &$lastMultipartString, &$savedPart) {
            list($method, $endpoint, $content) = $hook->args;
            $method = strtoupper($method);
            list($endpoint, $args, $query) = (function() { return [$this->endpoint, $this->args, $this->query]; })->call($endpoint);
            $span->resource = "$method /$endpoint";
            $span->name = "discord.http.request";
            $map = [
                "answer_id" => "discord.poll.answer_id",
                "application_id" => "discord.application.id",
                "auto_moderation_rule_id" => "discord.auto_moderation.rule.id",
                "channel_id" => "discord.channel.id",
                "code" => null,
                "command_id" => "discord.command.id",
                "emoji" => "discord.emoji.id",
                "emoji_id" => "discord.emoji.id",
                "entitlement_id" => "discord.",
                "guild_id" => "discord.guild.id",
                "guild_scheduled_event_id" => "discord.scheduled_event.id",
                "integration_id" => "discord.integration.id",
                "interaction_id" => "discord.interation.id",
                "interaction_token" => "discord.interaction.token",
                "message_id" => "discord.message.id",
                "overwrite_id" => "discord.permission.id",
                "role_id" => "discord.role.id",
                "sku_id" => "discord.sku.id",
                "sound_id" => "discord.sound.id",
                "sticker_id" => "discord.sku.sticker_id",
                "subscription_id" => "discord.subscription.id",
                "template_code" => "discord.template.code",
                "thread_id" => "discord.thread.id",
                "user_id" => "discord.user.id",
                "webhook_id" => "discord.webhook.id",
                "webhook_token" => null,
            ];
            foreach ($args as $name => $arg) {
                if (isset($map[$name])) {
                    $span->meta[$map[$name]] = $arg;
                }
            }
            if (isset($query["thread_id"])) {
                $span->meta["discord.thread.id"] = $query["thread_id"];
            }
            if ($savedPart) {
                self::tagsFromPart($savedPart, $span);
            }
            if ($method != "GET" && $method != "DELETE" && isset($endpointHandlers[$endpoint])) {
                if ($content === $lastMultipartString) {
                    $content = $lastMultipart;
                }
                array_walk_recursive($content, $fn = function (&$value) use (&$fn) {
                    if ($value instanceof \JsonSerializable) {
                        $value = $value->jsonSerialize();
                        array_walk_recursive($value, $fn);
                    }
                });
                $endpointHandlers[$endpoint]($span, $content);
            }
            $hook->data = $endpoint;
        }, function () {

        });

        \DDTrace\install_hook('Discord\Helpers\Multipart::__toString', null, function (HookData $hook) use (&$lastMultipart, &$lastMultipartString) {
            if ($hook->returned != "") {
                $lastMultipart = $this;
                $lastMultipartString = $hook->returned;
            }
        });

        \DDTrace\install_hook('Discord\Repository\AbstractRepositoryTrait::save', function () use (&$savedPart) {
            $savedPart = $this;
        }, function () use (&$savedPart) {
            $savedPart = null;
        });

        return Integration::LOADED;
    }

    private static function mapPermissionType(int $type)
    {
        static $types = [];
        if (!$types) {
            $class = new \ReflectionClass(\Discord\Parts\Interactions\Command\Permission::class);
            foreach ($class->getConstants() as $name => $value) {
                if (strpos($name, "TYPE_") === 0) {
                    $types[$value] = strtolower(substr($name, 5));
                }
            }
        }
        return $types[$type] ?? $type;
    }

    private static function mapRuleTriggerType(int $type)
    {
        static $types = [];
        if (!$types) {
            $class = new \ReflectionClass(\Discord\Parts\Guild\AutoModeration\Rule::class);
            foreach ($class->getConstants() as $name => $value) {
                if (strpos($name, "TRIGGER_TYPE_") === 0) {
                    $types[$value] = strtolower(substr($name, 13));
                }
            }
        }
        return $types[$type] ?? $type;
    }

    private static function mapAuditEntryType(int $type)
    {
        static $types = [];
        if (!$types) {
            $class = new \ReflectionClass(\Discord\Parts\Guild\AuditLog\Entry::class);
            foreach ($class->getConstants() as $name => $value) {
                $types[$value] = strtolower($name);
            }
        }
        return $types[$type] ?? $type;
    }

    private static function mapScheduledEventStatus(int $status)
    {
        static $statuses = [];
        if (!$statuses) {
            $class = new \ReflectionClass(\Discord\Parts\Guild\ScheduledEvent::class);
            foreach ($class->getConstants() as $name => $value) {
                if (strpos($name, "STATUS_") === 0) {
                    $statuses[$value] = strtolower(substr($name, 7));
                }
            }
        }
        return $statuses[$status] ?? $status;
    }

    private static function mapScheduledEventEntityType(int $type)
    {
        static $types = [];
        if (!$types) {
            $class = new \ReflectionClass(\Discord\Parts\Guild\ScheduledEvent::class);
            foreach ($class->getConstants() as $name => $value) {
                if (strpos($name, "ENTITY_TYPE_") === 0) {
                    $types[$value] = strtolower(substr($name, 12));
                }
            }
        }
        return $types[$type] ?? $type;
    }

    private static function mapInteractionType(int $type)
    {
        static $types = [];
        if (!$types) {
            $class = new \ReflectionClass(\Discord\Parts\Interactions\Interaction::class);
            foreach ($class->getConstants() as $name => $value) {
                if (strpos($name, "TYPE_") === 0) {
                    $types[$value] = strtolower(substr($name, 5));
                }
            }
        }
        return $types[$type] ?? $type;
    }

    private static function mapInteractionResponseType(int $type)
    {
        static $types = [];
        if (!$types) {
            $class = new \ReflectionClass(\Discord\Parts\Interactions\Interaction::class);
            foreach ($class->getConstants() as $name => $value) {
                if (strpos($name, "RESPONSE_TYPE_") === 0) {
                    $types[$value] = strtolower(substr($name, 14));
                }
            }
        }
        return $types[$type] ?? $type;
    }

    private static function mapMessageType(int $type)
    {
        static $types = [];
        if (!$types) {
            $class = new \ReflectionClass(\Discord\Parts\Channel\Message::class);
            foreach ($class->getConstants() as $name => $value) {
                if (strpos($name, "TYPE_") === 0) {
                    $types[$value] = strtolower(substr($name, 5));
                }
            }
        }
        return $types[$type] ?? $type;
    }

    private static function mapMessageFlags(int $flags)
    {
        static $allFlags = [];
        if (!$allFlags) {
            $class = new \ReflectionClass(\Discord\Parts\Channel\Message::class);
            foreach ($class->getConstants() as $name => $value) {
                if (strpos($name, "FLAG_") === 0) {
                    $allFlags[$value] = strtolower(substr($name, 5));
                }
            }
        }
        $found = [];
        foreach ($allFlags as $flag => $name) {
            if ($flags & $flag) {
                $found[] = $name;
            }
        }
        return json_encode($found);
    }

    private static function mapChannelType(int $type)
    {
        static $types = [];
        if (!$types) {
            $class = new \ReflectionClass(\Discord\Parts\Channel\Channel::class);
            foreach ($class->getConstants() as $name => $value) {
                if (strpos($name, "TYPE_") === 0) {
                    $types[$value] = strtolower(substr($name, 5));
                }
            }
        }
        return $types[$type] ?? $type;
    }

    private static function mapButtonStyle(int $style)
    {
        static $styles = [];
        if (!$styles) {
            $class = new \ReflectionClass(\Discord\Builders\Components\Button::class);
            foreach ($class->getConstants() as $name => $value) {
                if (strpos($name, "STYLE_") === 0) {
                    $styles[$value] = strtolower(substr($name, 5));
                }
            }
        }
        return $styles[$style] ?? $style;
    }

    private static function mapMemberFlags(int $flags) {
        static $allFlags = [];
        if (!$allFlags) {
            $class = new \ReflectionClass(\Discord\Parts\User\Member::class);
            foreach ($class->getConstants() as $name => $value) {
                if (strpos($name, "FLAGS_") === 0) {
                    $allFlags[$value] = strtolower(substr($name, 6));
                }
            }
        }
        $found = [];
        foreach ($allFlags as $flag => $name) {
            if ($flags & $flag) {
                $found[] = $name;
            }
        }
        return json_encode($found);
    }

    private static function mapPermissionBitset(int $permissions) {
        static $allPermissions = [];
        if (!$allPermissions) {
            foreach (ChannelPermission::getPermissions() as $name => $value) {
                $allPermissions[1 << $value] = $name;
            }
            foreach (Permission::ROLE_PERMISSIONS as $name => $value) {
                $allPermissions[1 << $value] = $name;
            }
        }
        $found = [];
        foreach ($allPermissions as $permission => $name) {
            if ($permissions & $permission) {
                $found[] = $name;
            }
        }
        return json_encode($found);
    }

    private static function mapAutomoderationPreset(int $preset)
    {
        static $presets = [];
        if (!$presets) {
            $class = new \ReflectionClass(\Discord\Parts\Guild\AutoModeration\Rule::class);
            foreach ($class->getConstants() as $name => $value) {
                if (strpos($name, "KEYWORD_PRESET_") === 0) {
                    $presets[$value] = strtolower(substr($name, 14));
                }
            }
        }
        return $presets[$preset] ?? $preset;
    }

    private static function mapRuleEventType(int $type)
    {
        static $types = [];
        if (!$types) {
            $class = new \ReflectionClass(\Discord\Parts\Guild\AutoModeration\Rule::class);
            foreach ($class->getConstants() as $name => $value) {
                if (strpos($name, "EVENT_TYPE_") === 0) {
                    $types[$value] = strtolower(substr($name, 11));
                }
            }
        }
        return $types[$type] ?? $type;
    }

    private static function mapRuleActionType(int $type)
    {
        static $types = [];
        if (!$types) {
            $class = new \ReflectionClass(\Discord\Parts\Guild\AutoModeration\Action::class);
            foreach ($class->getConstants() as $name => $value) {
                if (strpos($name, "TYPE_") === 0) {
                    $types[$value] = strtolower(substr($name, 5));
                }
            }
        }
        return $types[$type] ?? $type;
    }

    private static function mapApplicationCommandType(int $type)
    {
        static $types = [];
        if (!$types) {
            $class = new \ReflectionClass(\Discord\Parts\Interactions\Command\Command::class);
            foreach ($class->getConstants() as $name => $value) {
                $types[$value] = strtolower($name);
            }
        }
        return $types[$type] ?? $type;
    }

    private static function mapSystemChannelFlags(int $flags) {
        static $allFlags = [];
        if (!$allFlags) {
            $class = new \ReflectionClass(\Discord\Parts\Guild\Guild::class);
            foreach ($class->getConstants() as $name => $value) {
                if (strpos($name, "SUPPRESS_") === 0) {
                    $allFlags[$value] = strtolower(substr($name, 9));
                }
            }
        }
        $found = [];
        foreach ($allFlags as $flag => $name) {
            if ($flags & $flag) {
                $found[] = $name;
            }
        }
        return json_encode($found);
    }
}

// handler->handle
// |> emit

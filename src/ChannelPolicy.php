<?php
declare(strict_types=1);

namespace App;

use Discord\Parts\Channel\Channel;
use Discord\Parts\Guild\Guild;

/** Erkennt automatisch, welche Channels nur fuer das Team (oder gar nicht oeffentlich) sichtbar sind. */
final class ChannelPolicy
{
    /**
     * Liefert null, wenn der Channel gelesen werden darf, sonst den Grund.
     *
     * @param string[] $teamRoles
     */
    public static function autoReason(Guild $guild, Channel $channel, array $teamRoles): ?string
    {
        $everyone = $guild->roles->get('id', $guild->id);
        $view = $everyone ? (bool) $everyone->permissions->view_channel : true;
        $history = $everyone ? (bool) $everyone->permissions->read_message_history : true;

        $allowedRoles = [];
        $allowedMembers = 0;
        foreach ($channel->overwrites as $ow) {
            $isRole = (int) $ow->type === 0;
            if ($isRole && $ow->id === $guild->id) {
                if ($ow->deny->view_channel) {
                    $view = false;
                }
                if ($ow->allow->view_channel) {
                    $view = true;
                }
                if ($ow->deny->read_message_history) {
                    $history = false;
                }
                if ($ow->allow->read_message_history) {
                    $history = true;
                }
                continue;
            }
            if ($ow->allow->view_channel) {
                if ($isRole) {
                    $allowedRoles[] = $ow->id;
                } else {
                    $allowedMembers++;
                }
            }
        }

        if ($view && $history) {
            return null; // fuer @everyone sichtbar
        }
        if ($view && !$history) {
            return 'Nachrichtenverlauf fuer @everyone gesperrt';
        }

        // Fuer @everyone nicht sichtbar: nur lesen, wenn eine Nicht-Team-Rolle (z.B. "Spieler") Zugriff hat.
        $nonTeam = array_diff($allowedRoles, $teamRoles);
        if ($allowedMembers === 0 && $nonTeam !== []) {
            return null;
        }
        return 'nur fuer Team/einzelne Personen sichtbar';
    }
}

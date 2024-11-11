<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace smsgateway_modica;

use core_sms\manager;
use core_sms\message;

/**
 * Modica SMS gateway.
 *
 * @see https://confluence.modicagroup.com/display/DC/Mobile+Gateway+REST+API#MobileGatewayRESTAPI-Sendingtoasingledestination
 * @package    smsgateway_modica
 * @copyright  2024 Safat Shahin <safat.shahin@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gateway extends \core_sms\gateway {

    #[\Override]
    public function send(
        message $message,
    ): message {
        global $DB;
        // Get the config from the message record.
        $modicaconfig = $DB->get_field(
            table: 'sms_gateways',
            return: 'config',
            conditions: ['id' => $message->gatewayid, 'enabled' => 1, 'gateway' => 'smsgateway_modica\gateway',],
        );
        $status = \core_sms\message_status::GATEWAY_NOT_AVAILABLE;
        if ($modicaconfig) {
            $config = (object) json_decode($modicaconfig, true, 512, JSON_THROW_ON_ERROR);
            $recipientnumber = manager::format_number(
                phonenumber: $message->recipientnumber,
                countrycode: isset($config->countrycode) ?? null,
            );

            $params = [
                'http' => isset($config->modica_url) ? 'https://api.modicagroup.com/rest/gateway/messages' : $config->modica_url,
                'username' => $config->modica_application_name,
                'password' => $config->modica_application_password,
            ];

            $json = json_encode(['destination' => $recipientnumber, 'content' => $message->content,], JSON_THROW_ON_ERROR);

            // TODO: Convert this code to use guzzle.
            $curl = new \curl();
            $curl->setHeader(['Accept: application/json', 'Expect:']);
            $curl->post(
                $params['http'],
                $json,
                [
                    'CURLOPT_USERPWD' => $params['username'] . ':' . $params['password'],
                ],
            );
            $info = $curl->get_info();
            if(!empty($info['http_code']) && $info['http_code'] === 201) {
                $status = \core_sms\message_status::GATEWAY_SENT;
            } else {
                $status = \core_sms\message_status::GATEWAY_FAILED;
            }
        }

        return $message->with(
            status: $status,
        );
    }

    #[\Override]
    public function get_send_priority(message $message): int {
        return 50;
    }
}

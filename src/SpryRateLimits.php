<?php

/**
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

namespace Spry\SpryProvider;

use Spry\Spry;

/**
 * Rate Limit Provider for Spry
 */
class SpryRateLimits
{
    /**
     * Constructor for Rate Limits
     *
     * @access public
     *
     * @return void
     */
    public static function initiate()
    {
        $settings = self::getSettings();

        if ($settings['driver'] === 'db' && !empty($settings['dbTable'])) {
            Spry::addFilter('configure', function ($config) use ($settings) {
                $config->db['schema']['tables'][$settings['dbTable']] = [
                    'columns' => [
                        'key_name' => [
                            'type' => 'string',
                        ],
                        'key_value' => [
                            'type' => 'string',
                        ],
                        'path' => [
                            'type' => 'string',
                        ],
                        'expires' => [
                            'type' => 'int',
                        ],
                        'current' => [
                            'type' => 'int',
                        ],
                    ],
                ];

                return $config;
            });
        }

        // Don't run Rate Limits for the cli or any Background Processes
        if (Spry::isCli() || Spry::isBackgroundProcess() || empty($settings['driver'])) {
            return;
        }

        Spry::addHook('configure', [__CLASS__, 'resetLimits']);
        Spry::addHook('setRoute', [__CLASS__, 'runRouteRateLimit']);

        if (!empty($settings['default'])) {
            if (!empty($settings['default']['hook'])) {
                Spry::addHook($settings['default']['hook'], [__CLASS__, 'runDefaultRateLimit']);
            } else {
                Spry::addHook('configure', [__CLASS__, 'runDefaultRateLimit']);
            }
        }
    }

    /**
     * Runs the Default Global Rate Limits
     *
     * @param array $route
     *
     * @access public
     *
     * @return void
     */
    public static function runDefaultRateLimit($route = [])
    {
        $limits = !empty($route['limits']) ? $route['limits'] : null;
        if (!isset($route['limits'])) {
            $settings = self::getSettings();
            $limits = $settings['default'];
        }

        if (!empty($limits)) {
            $routePath = !empty($route['path']) ? $route['path'] : '_default_';
            $keys = Spry::runFilter('spryRateLimitKeys', ['ip' => self::getIp()]);
            self::runRateLimit($limits, $keys, $settings, $routePath);
        }
    }

    /**
     * Runs the Route Rate Limits
     *
     * @param array $route
     *
     * @access public
     *
     * @return void
     */
    public static function runRouteRateLimit($route)
    {
        if (!empty($route['limits'])) {
            if (is_array($route['limits']) && !is_array($route['limits'][0])) {
                $route['limits'] = [$route['limits']];
            }

            $keys = Spry::runFilter('spryRateLimitKeys', ['ip' => self::getIp()]);

            $settings = self::getSettings();

            foreach ($route['limits'] as $routeLimit) {
                self::runRateLimit($routeLimit, $keys, $settings, str_replace(['/', '\\', '{', '}', ':', '?'], '_', $route['path']));
            }
        }
    }

    /**
     * Checks for Rate limits that have expired and clears them
     *
     * @access public
     *
     * @return void
     */
    public static function resetLimits()
    {
        $settings = self::getSettings();

        if ($settings['driver'] === 'file' && !empty($settings['fileDirectory']) && is_dir($settings['fileDirectory'])) {
            $files = glob(rtrim($settings['fileDirectory'], '/').'/*');

            if (!empty($files)) {
                foreach ($files as $file) {
                    list($key, $keyValue, $path, $expires) = explode(':', basename($file));

                    if (empty($expires) || intval($expires) <= time()) {
                        unlink($file);
                    }
                }
            }
        }

        if ($settings['driver'] === 'db' && !empty($settings['dbTable'])) {
            if (empty($settings['excludeTests'])) {
                $settings['dbMeta']['excludeTestData'] = true;
            }
            if (in_array(Spry::config()->db['prefix'].$settings['dbTable'], Spry::db()->getTables(), true)) {
                Spry::db($settings['dbMeta'])->delete($settings['dbTable'], ['expires[<=]' => time()]);
            }
        }
    }

    /**
     * Checks and Validates the Rate limit
     *
     * @param array  $rateLimit
     * @param array  $keys
     * @param array  $settings
     * @param string $routePath
     *
     * @access private
     *
     * @return void
     */
    private static function runRateLimit($rateLimit, $keys, $settings, $routePath)
    {
        if (!isset($rateLimit['excludeTests']) && isset($settings['excludeTests'])) {
            $rateLimit['excludeTests'] = $settings['excludeTests'];
        }

        if (empty($rateLimit['excludeTests'])) {
            $settings['dbMeta']['excludeTestData'] = true;
        }

        if (empty($rateLimit['limit']) || empty($rateLimit['within']) || (Spry::isTest() && !empty($rateLimit['excludeTests']))) {
            return; // no Limits return
        }

        $limit = intval($rateLimit['limit']);
        $within = intval($rateLimit['within']);

        if (!$limit || !$within) {
            return; // no Limits return
        }

        if (!isset($rateLimit['by'])) {
            $rateLimit['by'] = 'ip';
        }
        if (!is_array($rateLimit['by'])) {
            if (is_string($rateLimit['by'])) {
                $rateLimit['by'] = [$rateLimit['by']];
            } else {
                $rateLimit['by'] = ['ip'];
            }
        }
        foreach ($rateLimit['by'] as $by) {
            if (!empty($keys[$by])) {
                break;
            }
        }

        if (empty($keys[$by])) {
            Spry::stop(71);
        }

        $expires = time() + intval($within);
        $current = 0;
        $file = '';
        $entryId = 0;

        if ($settings['driver'] === 'file' && !empty($settings['fileDirectory'])) {
            if (!is_dir($settings['fileDirectory'])) {
                mkdir($settings['fileDirectory'], 0755, true);
            }
            if (!is_dir($settings['fileDirectory'])) {
                Spry::stop(72);
            }

            $fileKey = $by.':'.$keys[$by].':'.$routePath;

            $files = glob(rtrim($settings['fileDirectory'], '/').'/'.$fileKey.'*');

            if (!empty($files[0])) {
                $file = $files[0];
                $fileVars = explode(':', basename($file));

                if (!empty($fileVars[3]) && is_numeric($fileVars[3])) {
                    $expires = intval($fileVars[3]);
                    if (!empty($expires) && $expires > time()) {
                        $current = intval(file_get_contents($files[0]));
                    }
                }
            } else {
                $file = rtrim($settings['fileDirectory'], '/').'/'.$fileKey.':'.$expires;
            }
        }

        if ($settings['driver'] === 'db' && !empty($settings['dbTable'])) {
            if (in_array(Spry::config()->db['prefix'].$settings['dbTable'], Spry::db()->getTables(), true)) {
                $entry = Spry::db($settings['dbMeta'])->get($settings['dbTable'], ['id', 'current', 'expires'], ['key_name' => $by, 'key_value' => $keys[$by], 'path' => $routePath, 'expires[>]' => time()]);

                if (!empty($entry['id'])) {
                    $entryId = $entry['id'];
                    $current = intval($entry['current']);
                    $expires = intval($entry['expires']);
                } else {
                    if (Spry::db($settings['dbMeta'])->insert($settings['dbTable'], ['key_name' => $by, 'key_value' => $keys[$by], 'path' => $routePath, 'expires' => $expires])) {
                        $entryId = Spry::db()->id();
                    }
                }
            }
        }

        $current++;

        if ($current > $limit) {
            Spry::stop(70, null, null, ['Reset at '.$expires]);
        }

        if ($settings['driver'] === 'file' && !empty($settings['fileDirectory']) && $file) {
            file_put_contents($file, $current);
        }

        if ($settings['driver'] === 'db' && !empty($settings['dbTable']) && $entryId) {
            if (in_array(Spry::config()->db['prefix'].$settings['dbTable'], Spry::db()->getTables(), true)) {
                Spry::db($settings['dbMeta'])->update($settings['dbTable'], ['current' => $current], ['id' => $entryId]);
            }
        }
    }

    /**
     * Gets the Settings from the Config for rateLimits
     *
     * @access private
     *
     * @return string
     */
    private static function getSettings()
    {
        $settings = !empty(Spry::config()->rateLimits) ? Spry::config()->rateLimits : [];

        $settings = array_merge([
            'driver' => null,
            'dbMeta' => null,
            'excludeTests' => false,
            'default' => null,
        ], $settings);

        return $settings;
    }

    /**
     * Gets the Ip Address and detects if is CLI or Background Process.
     *
     * @access private
     *
     * @return string
     */
    private static function getIp()
    {
        if (empty($_SERVER['REMOTE_ADDR']) && Spry::isCli()) {
            return '127.0.0.1';
        }

        return $_SERVER['REMOTE_ADDR'];
    }
}

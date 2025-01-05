<?php

class jMQTTPlugin {

    /**
     * cron callback
     * check MQTT Clients are up and connected
     */
    public static function cron() {
        jMQTTDaemon::checkAllMqttClients();
        jMQTTDaemon::pluginStats();
    }


    /**
     * Provides Python dependancy information
     */
    private static function pythonRequirementsInstalled(string $pythonPath, string $requirementsPath) {
        if (!file_exists($pythonPath) || !file_exists($requirementsPath)) {
        return false;
        }
        exec("{$pythonPath} -m pip freeze", $packages_installed);
        $packages = join("||", $packages_installed);
        exec("cat {$requirementsPath}", $packages_needed);
        foreach ($packages_needed as $line) {
        if (preg_match('/([^\s]+)[\s]*([>=~]=)[\s]*([\d+\.?]+)$/', $line, $need) === 1) {
            if (preg_match('/' . $need[1] . '==([\d+\.?]+)/', $packages, $install) === 1) {
            if ($need[2] == '==' && $need[3] != $install[1]) {
                return false;
            } elseif (version_compare($need[3], $install[1], '>')) {
                return false;
            }
            } else {
            return false;
            }
        }
        }
        return true;
    }

    /**
     * Provides dependancy information
     *
     * @return string[] Status of dependencies
     */
    public static function dependancy_info() {
        $phpReq = __DIR__ . '/../../resources/JsonPath-PHP/vendor/composer/installed.json';
        $pipBin = __DIR__ . '/../../resources/jmqttd/venv/bin/pip';
        $pythonBin = __DIR__ . '/../../resources/jmqttd/venv/bin/python3';
        $pythonReq = __DIR__ . '/../../resources/python-requirements/requirements.txt';
        $return = array();
        $return['log'] = log::getPathToLog('jMQTT_dep');
        $return['progress_file'] = jeedom::getTmpFolder('jMQTT') . '/dependancy';
        $return['state'] = 'ok';
        if (file_exists($return['progress_file'])) {
            $return['state'] = 'in_progress';
            jMQTT::logger('debug', sprintf('Dependencies being installed... (%s%%)',
                trim(file_get_contents($return['progress_file']))
            ));
        } elseif (exec("sudo cat " . $phpReq . " 2>/dev/null | grep galbar/jsonpath | wc -l") < 1) {
            jMQTT::logger('debug', 'Relaunch dependencies, PHP package JsonPath is missing');
            $return['state'] = 'nok';
        } elseif (!file_exists($pipBin) || !file_exists($pythonBin)) {
            jMQTT::logger('debug', 'Relaunch dependencies, the Python venv has not been created yet');
            $return['state'] = 'nok';
        } elseif (!self::pythonRequirementsInstalled($pythonBin, $pythonReq)) {
            jMQTT::logger('debug', 'Relaunch dependencies, at least one required Python library is missing from the venv');
            $return['state'] = 'nok';
        } else {
            jMQTT::logger('debug', 'Dependencies installed.');
        }
        return $return;
    }

    /**
     * Provides dependancy installation script
     *
     * @return string[]
     */
    public static function dependancy_install() {
        $depLogFile = 'jMQTT_dep';
        $depLogFullPath = log::getPathToLog($depLogFile);
        jMQTT::logger('info', sprintf(
            __('Installation des dépendances, voir log dédié (%s)', __FILE__),
            $depLogFile
        ));
        $update = update::byLogicalId('jMQTT');
        $version = $update->getLocalVersion();
        $branch = $update->getConfiguration()['version'];
        $pythonBin = __DIR__ . '/../../resources/venv/bin/python3';
        shell_exec(
            'echo "\n\n================================================================================\n'.
            '== Jeedom '.jeedom::version().' '.jeedom::getHardwareName().
            ' in $(lsb_release -d -s | xargs echo -n) on $(arch | xargs echo -n)/'.
            '$(dpkg --print-architecture | xargs echo -n)/$(getconf LONG_BIT | xargs echo -n)bits\n'.
            '== $('.$pythonBin.' -VV | xargs echo -n)\n'.
            '== jMQTT v'.config::byKey('version', 'jMQTT', 'unknown', true).
            ' ('.$version.') branch:'.$branch.
            ' previously:v'.config::byKey('previousVersion', 'jMQTT', 'unknown', true).
            '" >> '.$depLogFullPath
        );

        return array(
            'script' => __DIR__ . '/../../resources/install_#stype#.sh',
            'log' => $depLogFullPath
        );
      }

    /**
     * Additionnal information for a new Community post
     *
     * @return string
     */
    public static function getConfigForCommunity() {
        $hw = jeedom::getHardwareName();
        if ($hw == 'diy')
            $hw = trim(shell_exec('systemd-detect-virt'));
        if ($hw == 'none')
            $hw = 'diy';
        $distrib = trim(shell_exec('. /etc/*-release && echo $ID $VERSION_ID'));
        $res = 'OS: ' . $distrib . ' on ' . $hw;
        $res .= ' ; PHP: ' . phpversion();
        $res .= ' ; Python: ' . trim(shell_exec("python3 -V | cut -d ' ' -f 2"));
        $res .= '<br/>jMQTT: v' . config::byKey('version', 'jMQTT', 'unknown', true);
        $res .= ' ; Brokers: ' . count(jMQTT::getBrokers());
        $nbEq = 0;
        foreach (jMQTT::getNonBrokers() as $brk) {
            $nbEq += count($brk);
        }
        $res .= ' ; Equipments: ' . $nbEq;
        $res .= ' ; cmds: ' . count(cmd::searchConfiguration('', jMQTT::class));
        return $res;
    }

    // Check install status of Mosquitto service
    public static function mosquittoCheck() {
        $res = array('installed' => false, 'by' => '', 'message' => '', 'service' => '', 'config' => '');
        $retval = 255;
        $output = null;
        // Check if Mosquitto package is installed
        exec('dpkg -s mosquitto 2> /dev/null 1> /dev/null', $output, $retval); // retval = 1 not installed ; 0 installed

        // Not installed return default values
        if ($retval != 0) {
            $res['message'] = __("Mosquitto n'est pas installé en tant que service.", __FILE__);
            try {
                // Checking for Mosquitto installed in Docker by MQTT Manager
                if (is_object(update::byLogicalId('mqtt2'))
                    && plugin::byId('mqtt2')->isActive()
                    && config::byKey('mode', 'mqtt2', 'NotThere') == 'docker') {
                    // Plugin Active and mqtt2 mode is docker
                    $res['by'] = 'MQTT Manager ' . __('(en docker)', __FILE__);
                    $res['message'] = __('Mosquitto est installé <b>en docker</b> par', __FILE__);
                    $res['message'] .= ' <a class="control-label danger" href="index.php?v=d&p=plugin&id=mqtt2">';
                    $res['message'] .= 'MQTT Manager</a> (mqtt2).';
                }
            } catch (Throwable $e) {
                if (log::getLogLevel('jMQTT') > 100) {
                    jMQTT::logger('error', sprintf(
                        __("%1\$s() a levé l'Exception: %2\$s", __FILE__),
                        __METHOD__,
                        $e->getMessage()
                    ));
                } else {
                    jMQTT::logger(
                        'error',
                        str_replace(
                            "\n",
                            ' <br/> ',
                            sprintf(
                                __("%1\$s() a levé l'Exception: %2\$s", __FILE__).
                                ",<br/>@Stack: %3\$s.",
                                __METHOD__,
                                $e->getMessage(),
                                $e->getTraceAsString()
                            )
                        )
                    );
                }
            }
            return $res;
        }

        // Otherwise, it is Installed
        $res['installed'] = true;
        // Get service active status
        $res['service'] = ucfirst(shell_exec('systemctl status mosquitto.service | grep "Active:" | sed -r "s/^[^:]*: (.*)$/\1/"'));
        // Get service config file (***unused for now***)
        // $res['config'] = shell_exec('systemctl status mosquitto.service | grep -- " -c " | sed -r "s/^.* -c (.*)$/\1/"');

        // Read in Core config who is supposed to have installed Mosquitto
        $res['core'] = config::byKey('mosquitto::installedBy', '', 'Unknown');
        // TODO: Decide if `mosquitto::installedBy` is usefull
        //  When config key will be widely used, resolve Mosquitto installer here
        //  labels: quality, php

        // Check if mosquitto.service has been changed by mqtt2
        if (file_exists('/lib/systemd/system/mosquitto.service')
                && strpos(file_get_contents('/lib/systemd/system/mosquitto.service'), 'mqtt2') !== false) {
            $res['by'] = 'MQTT Manager ' . __('(en local)', __FILE__);
            $res['message'] = __('Mosquitto est installé par', __FILE__);
            $res['message'] .= ' <a class="control-label danger" target="_blank" href="index.php?v=d&p=plugin&id=mqtt2">';
            $res['message'] .= 'MQTT Manager</a> (mqtt2).';
        }
        // Check if ZigbeeLinker has modified Mosquitto config
        elseif (file_exists('/etc/mosquitto/mosquitto.conf')
                && preg_match('#^include_dir.*zigbee2mqtt/data/mosquitto/include#m',
                    file_get_contents('/etc/mosquitto/mosquitto.conf'))) {
            $res['by'] = 'ZigbeeLinker';
            $res['message'] = __('Mosquitto est installé par', __FILE__);
            $res['message'] .= ' <a class="control-label danger" target="_blank" href="index.php?v=d&p=plugin&id=zigbee2mqtt">';
            $res['message'] .= 'ZigbeeLinker</a> (zigbee2mqtt).';
        }
        // Check if jMQTT config file is in place
        elseif (file_exists('/etc/mosquitto/conf.d/jMQTT.conf')) {
            $res['by'] = 'jMQTT';
            $res['message'] = __('Mosquitto est installé par', __FILE__);
            $res['message'] .= ' <a class="control-label success disabled">jMQTT</a>.';
        }
        // Otherwise its considered to be a custom install
        else {
            $res['by'] = __("Inconnu", __FILE__);
            $res['message'] = __("Mosquitto n'a pas été installé par un plugin connu.", __FILE__);
        }
        return $res;
    }

    // Install Mosquitto service and create first broker eqpt
    public static function mosquittoInstall() {
        $retval = 255;
        $output = null;
        // Check if Mosquitto package is installed
        exec('dpkg -s mosquitto 2> /dev/null 1> /dev/null', $output, $retval);
        // retval = 1 not installed ; 0 installed
        if ($retval == 0) {
            jMQTT::logger('warning', __("Mosquitto est déjà installé sur ce système !", __FILE__));
            return;
        }

        // Apt-get mosquitto
        jMQTT::logger(
            'info',
            __("Mosquitto : Démarrage de l'installation, merci de patienter...", __FILE__)
        );
        shell_exec('sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -o Dpkg::Options::="--force-confask,confnew,confmiss" mosquitto');

        $retval = 255;
        $output = null;
        // Check if mosquitto has already been configured (/etc/mosquitto/conf.d/jMQTT.conf is present)
        exec('ls /etc/mosquitto/conf.d/jMQTT.conf 2>/dev/null | wc -w', $output, $retval); // retval = 1 conf ok ; 0 no conf
        if ($retval == 0) {
            shell_exec('sudo cp ' . __DIR__ . '/../../resources/mosquitto_jMQTT.conf /etc/mosquitto/conf.d/jMQTT.conf');
        }

        // Cleanup, just in case
        shell_exec('sudo systemctl daemon-reload');
        shell_exec('sudo systemctl enable mosquitto');
        shell_exec('sudo systemctl stop mosquitto');
        shell_exec('sudo systemctl start mosquitto');

        // Write in Core config that jMQTT has installed Mosquitto
        config::save('mosquitto::installedBy', 'jMQTT');
        jMQTT::logger('info', __("Mosquitto : Fin de l'installation.", __FILE__));

        // Looking for eqBroker pointing to local mosquitto
        $brokerexists = false;
        foreach(jMQTT::getBrokers() as $broker) {
            $hn = $broker->getConf(jMQTTConst::CONF_KEY_MQTT_ADDRESS);
            $ip = gethostbyname($hn);
            $localips = explode(' ', exec('sudo hostname -I'));
            if ($hn == '' || substr($ip, 0, 4) == '127.' || in_array($ip, $localips)) {
                $brokerexists = true;
                jMQTT::logger(
                    'info',
                    sprintf(
                        __("L'équipement Broker local #%s# existe déjà, pas besoin d'en créer un.", __FILE__),
                        $broker->getHumanName()
                    )
                );
                break;
            }
        }

        // Could not find a local eqBroker
        if (!$brokerexists) {
            jMQTT::logger(
                'info',
                __("Aucun équipement Broker local n'a été trouvé, création en cours...", __FILE__)
            );
            $brokername = 'local';

            // Looking for a conflict with eqBroker name
            $brokernameconflict = false;
            foreach(jMQTT::getBrokers() as $broker) {
                if ($broker->getName() == $brokername) {
                    $brokernameconflict = true;
                    break;
                }
            }
            if ($brokernameconflict) {
                $i = 0;
                do {
                    $i++;
                    $brokernameconflict = false;
                    $brokername = 'local'.$i;
                    foreach(jMQTT::getBrokers() as $broker) {
                        if ($broker->getName() == $brokername) {
                            $brokernameconflict = true;
                            break;
                        }
                    }
                } while ($brokernameconflict);
            }

            // Creating a new eqBroker to communicate with local Mosquitto
            $broker = new jMQTT();
            $broker->setType(jMQTTConst::TYP_BRK);
            $broker->setName($brokername);
            $broker->setIsEnable(1);
            $broker->save();
            jMQTT::logger(
                'info',
                sprintf(
                    __("L'équipement Broker #%s# a été créé.", __FILE__),
                    $broker->getHumanName()
                )
            );
        }
    }

    // Reinstall Mosquitto service over previous install
    public static function mosquittoRepare() {
        // Stop service
        shell_exec('sudo systemctl stop mosquitto');
        // Ensure no config is remaining
        shell_exec('sudo DEBIAN_FRONTEND=noninteractive rm -rf /etc/mosquitto');
        // Reinstall and force reapply service default config
        shell_exec('sudo DEBIAN_FRONTEND=noninteractive apt-get install --reinstall -y -o Dpkg::Options::="--force-confask,confnew,confmiss" mosquitto');
        // Apply jMQTT config
        shell_exec('sudo cp ' . __DIR__ . '/../../resources/mosquitto_jMQTT.conf /etc/mosquitto/conf.d/jMQTT.conf');
        // Cleanup, just in case
        shell_exec('sudo systemctl daemon-reload');
        shell_exec('sudo systemctl stop mosquitto');
        shell_exec('sudo systemctl enable mosquitto');
        shell_exec('sudo systemctl start mosquitto');
        // Write in Core config that jMQTT has installed Mosquitto
        config::save('mosquitto::installedBy', 'jMQTT');
    }

    // Purge Mosquitto service and all related config files
    public static function mosquittoRemove() {
        $retval = 255;
        $output = null;
        // Check if Mosquitto package is installed
        exec('dpkg -s mosquitto 2> /dev/null 1> /dev/null', $output, $retval); // retval = 1 not installed ; 0 installed
        if ($retval == 1) {
            event::add(
                'jeedom::alert',
                array(
                    'level' => 'danger',
                    'page' => 'plugin',
                    'message' => __("Mosquitto n'est pas installé sur ce système !", __FILE__)
                )
            );
            return;
        }
        // Remove package and /etc folder
        shell_exec('sudo DEBIAN_FRONTEND=noninteractive apt-get purge -y mosquitto');
        shell_exec('sudo DEBIAN_FRONTEND=noninteractive rm -rf /etc/mosquitto');
        // Remove from Core config that Mosquitto is installed
        config::remove('mosquitto::installedBy');
    }

}

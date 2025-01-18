#!/bin/bash
######################### INCLUSION LIB ##########################
BASE_DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
# wget https://raw.githubusercontent.com/NebzHB/dependance.lib/master/dependance.lib -O ${BASE_DIR}/dependance.lib &>/dev/null
# wget https://raw.githubusercontent.com/NebzHB/dependance.lib/master/i18n/en.json -O ${BASE_DIR}/dependance.en.json &>/dev/null
PROGRESS_FILENAME=dependancy
PLUGIN=$(basename "$(realpath ${BASE_DIR}/..)")
LANG_DEP=en
# TIMED=1
. ${BASE_DIR}/dependance.lib
##################################################################
# wget https://raw.githubusercontent.com/NebzHB/dependance.lib/master/pyenv.lib --no-cache -O ${BASE_DIR}/pyenv.lib &>/dev/null
. ${BASE_DIR}/pyenv.lib
TARGET_PYTHON_VERSION="3.11" # EOL=2027-10
VENV_DIR=${BASE_DIR}/jmqttd/venv
##################################################################

pre
step 0 "Synchronize the package index"
try sudo apt-get update

step 5 "Purge dynamic contents"
silent rm -rf ${BASE_DIR}/JsonPath-PHP/composer.lock
silent rm -rf ${BASE_DIR}/JsonPath-PHP/vendor
silent rm -rf ${BASE_DIR}/jmqttd/venv
silent rm -rf ${BASE_DIR}/jmqttd/__pycache__

step 10 "Install Composer"
try wget 'https://getcomposer.org/installer' -O ${BASE_DIR}/composer-setup.php
try php ${BASE_DIR}/composer-setup.php --install-dir=${BASE_DIR}/
try rm -f ${BASE_DIR}/composer-setup.php

step 13 "Install JsonPath-PHP library"
try sudo -u www-data php ${BASE_DIR}/composer.phar update --working-dir=${BASE_DIR}/JsonPath-PHP

step 18 "Remove Composer"
silent rm ${BASE_DIR}/composer.phar

autoSetupVenv

step 70 "Install required python3 libraries in venv"
try ${VENV_DIR}/bin/python3 -m pip install --upgrade -r ${BASE_DIR}/python-requirements/requirements.txt

step 80 "Restoring folders and files rights"
chown -Rh www-data:www-data ${VENV_DIR}

step 85 "Summary of installed packages"
${VENV_DIR}/bin/python3 -m pip freeze

post

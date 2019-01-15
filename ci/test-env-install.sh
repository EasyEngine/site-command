#!/usr/bin/env bash
function setup_php() {
    readonly LOG_FILE="/opt/easyengine/logs/install.log"
    # Adding software-properties-common for add-apt-repository.
    apt-get install -y software-properties-common
    # Adding ondrej/php repository for installing php, this works for all ubuntu flavours.
    add-apt-repository -y ppa:ondrej/php
    apt-get update
    # Installing php-cli, which is the minimum requirement to run EasyEngine
    apt-get -y install php7.2-cli

    php_modules=( pcntl curl sqlite3 )
    if command -v php > /dev/null 2>&1; then
      # Reading the php version.
      default_php_version="$(readlink -f /usr/bin/php | gawk -F "php" '{ print $2}')"
      for module in "${php_modules[@]}"; do
        if ! php -m | grep $module >> $LOG_FILE 2>&1; then
          echo "$module not installed. Installing..."
          apt install -y php$default_php_version-$module
        else
          echo "$module is already installed"
        fi
      done
    fi
}

setup_docker_compose() {
  curl -L https://github.com/docker/compose/releases/download/1.23.2/docker-compose-`uname -s`-`uname -m` -o /usr/local/bin/docker-compose
  chmod +x /usr/local/bin/docker-compose
}

setup_test_requirements() {
  setup_php
  setup_docker_compose
}

setup_test_requirements

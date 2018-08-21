#!/bin/bash

# called by Travis CI

# install dependencies
wget -qO ee https://rt.cx/ee4beta && sudo bash ee
rm ee

# Setup EE develop repo
cd ..
git clone https://github.com/EasyEngine/easyengine.git easyengine --depth=1
cd easyengine

# Copy tests to EE repo
rm -r features
cp -R ../$TEST_COMMAND/features .

# Install composer dependencies and update them for tests
composer update

# Place the command inside EE repo
rm -r vendor/easyengine/$TEST_COMMAND
cp -R ../$TEST_COMMAND vendor/easyengine/

# Create phar and test it
php -dphar.readonly=0 ./utils/make-phar.php easyengine.phar --quite  > /dev/null
sudo php easyengine.phar cli info
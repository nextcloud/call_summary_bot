# SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

# Makefile for building the project

app_name=call_summary_bot

project_dir=$(CURDIR)/../$(app_name)
build_dir=$(CURDIR)/build/artifacts
appstore_dir=$(build_dir)/appstore
source_dir=$(build_dir)/source
sign_dir=$(build_dir)/sign
package_name=$(app_name)
cert_dir=$(HOME)/.nextcloud/certificates
version+=main
composer=$(shell which composer 2> /dev/null)

all: appstore

# Dev env management
dev-setup: clean

# Installs and updates the composer dependencies. If composer is not installed
# a copy is fetched from the web
composer:
ifeq (, $(composer))
	@echo "No composer command available, downloading a copy from the web"
	mkdir -p $(build_tools_directory)
	curl -sS https://getcomposer.org/installer | php
	mv composer.phar $(build_tools_directory)
	php $(build_tools_directory)/composer.phar install --prefer-dist
	php $(build_tools_directory)/composer.phar update --prefer-dist
else
	composer install --prefer-dist
	composer update --prefer-dist
endif

release: appstore create-tag

create-tag:
	git tag -a v$(version) -m "Tagging the $(version) release."
	git push origin v$(version)

clean:
	rm -rf $(build_dir)

appstore: dev-setup
	mkdir -p $(sign_dir)
	rsync -a \
	--exclude=/.git \
	--exclude=/.github \
	--exclude=/.tx \
	--exclude=/build \
	--exclude=/docs \
	--exclude=/node_modules \
	--exclude=/src \
	--exclude=/tests \
	--exclude=/vendor \
	--exclude=/vendor-bin \
	--exclude=/.eslintrc.js \
	--exclude=/.l10nignore \
	--exclude=/.php-cs-fixer.cache \
	--exclude=/.php-cs-fixer.dist.php \
	--exclude=/.gitattributes \
	--exclude=/.gitignore \
	--exclude=/.scrutinizer.yml \
	--exclude=/.travis.yml \
	--exclude=/babel.config.js \
	--exclude=/composer.json \
	--exclude=/composer.lock \
	--exclude=/Makefile \
	--exclude=/package.json \
	--exclude=/package-lock.json \
	--exclude=/psalm.xml \
	--exclude=/README.md \
	--exclude=/rector.php \
	--exclude=/stylelint.config.js \
	--exclude=/webpack.js \
	$(project_dir)/ $(sign_dir)/$(app_name)
	@if [ -f $(cert_dir)/$(app_name).key ]; then \
		echo "Signing app files…"; \
		php ../../occ integrity:sign-app \
			--privateKey=$(cert_dir)/$(app_name).key\
			--certificate=$(cert_dir)/$(app_name).crt\
			--path=$(sign_dir)/$(app_name); \
	fi
	tar -czf $(build_dir)/$(app_name).tar.gz \
		-C $(sign_dir) $(app_name)
	@if [ -f $(cert_dir)/$(app_name).key ]; then \
		echo "Signing package…"; \
		openssl dgst -sha512 -sign $(cert_dir)/$(app_name).key $(build_dir)/$(app_name).tar.gz | openssl base64; \
	fi


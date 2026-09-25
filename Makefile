# Reproducible packaging for pkg_ecskc_customisations.

SHELL := /bin/sh

EXT_NAME := com_ecskccustom
COM_ROOT := extensions/com_ecskccustom
MANIFEST := $(COM_ROOT)/com_ecskccustom.xml
SYSTEM_PLUGIN_ROOT := extensions/plg_system_ecskccustom
INSTALLER_PLUGIN_ROOT := extensions/plg_installer_ecskccustom

# Explicit package inventory. script.php also ships at the archive root because
# Joomla looks there on install and under admin/ on uninstall.
COMPONENT_FILES := \
	com_ecskccustom.xml \
	admin/access.xml \
	admin/config.xml \
	admin/script.php \
	admin/forms/filter_targets.xml \
	admin/language/en-GB/com_ecskccustom.ini \
	admin/language/en-GB/com_ecskccustom.sys.ini \
	admin/payload/index.html \
	admin/rules/index.html \
	admin/schema/patch.xsd \
	admin/services/provider.php \
	admin/src/Access/WritePermission.php \
	admin/src/Controller/DisplayController.php \
	admin/src/Controller/TargetsController.php \
	admin/src/Core/Condition/ConditionEvaluator.php \
	admin/src/Core/Condition/ConditionGroup.php \
	admin/src/Core/Condition/ConditionOutcome.php \
	admin/src/Core/Condition/RuleCondition.php \
	admin/src/Core/Environment/EnvironmentProviderInterface.php \
	admin/src/Core/Environment/EnvironmentResolver.php \
	admin/src/Core/Environment/EnvironmentValues.php \
	admin/src/Core/Environment/RawEnvironment.php \
	admin/src/Core/Hash/HashAlgorithm.php \
	admin/src/Core/Hash/Hasher.php \
	admin/src/Core/Hash/HashResult.php \
	admin/src/Core/Operational/ExtensionRecordRead.php \
	admin/src/Core/Operational/OperationalCheckProviderInterface.php \
	admin/src/Core/Operational/SystemPluginCheck.php \
	admin/src/Core/Package/ExclusiveCopy.php \
	admin/src/Core/Package/PackagePaths.php \
	admin/src/Core/Package/ProcDescriptor.php \
	admin/src/Core/Package/StateArchive.php \
	admin/src/Core/Package/StateArchiveFailure.php \
	admin/src/Core/Path/PathDecision.php \
	admin/src/Core/Path/PathPolicy.php \
	admin/src/Core/Report/OperationalCheck.php \
	admin/src/Core/Report/OperationalChecks.php \
	admin/src/Core/Report/ReportedValue.php \
	admin/src/Core/Report/RunCounts.php \
	admin/src/Core/Report/PlannedOperation.php \
	admin/src/Core/Report/RunReport.php \
	admin/src/Core/Report/TargetReport.php \
	admin/src/Core/Rule/MergedSetValidator.php \
	admin/src/Core/Rule/RuleAction.php \
	admin/src/Core/Rule/RuleLoader.php \
	admin/src/Core/Rule/RuleLoadResult.php \
	admin/src/Core/Rule/RuleObject.php \
	admin/src/Core/Rule/RuleSet.php \
	admin/src/Core/Rule/SchemaValidator.php \
	admin/src/Core/ReadOnlyRunnerInterface.php \
	admin/src/Core/Runner.php \
	admin/src/Core/WriteRunnerInterface.php \
	admin/src/Core/State/BackupOutcome.php \
	admin/src/Core/State/BackupStore.php \
	admin/src/Core/State/BoundBackupDirectory.php \
	admin/src/Core/State/EventLog.php \
	admin/src/Core/State/EventLogHistory.php \
	admin/src/Core/State/EventLogRead.php \
	admin/src/Core/State/EventRecord.php \
	admin/src/Core/State/FreezeMarkers.php \
	admin/src/Core/State/RunLock.php \
	admin/src/Core/State/RunMarker.php \
	admin/src/Core/State/RunPhase.php \
	admin/src/Core/State/RunState.php \
	admin/src/Core/State/RunStateReader.php \
	admin/src/Core/State/StateFile.php \
	admin/src/Core/State/WriteHistoryInterface.php \
	admin/src/Core/State/WriteIntent.php \
	admin/src/Core/State/WriteIntentRead.php \
	admin/src/Core/State/WriteIntentStore.php \
	admin/src/Core/Walk/ActionSelection.php \
	admin/src/Core/Walk/ObjectOutcome.php \
	admin/src/Core/Walk/ObjectWalker.php \
	admin/src/Core/Walk/ProposedOperation.php \
	admin/src/Core/Walk/TargetState.php \
	admin/src/Core/Walk/TargetStatus.php \
	admin/src/Core/Walk/WalkContext.php \
	admin/src/Core/Write/FreezeGuard.php \
	admin/src/Core/Write/Freezer.php \
	admin/src/Core/Write/MutationTransaction.php \
	admin/src/Core/Write/RecoveryOutcome.php \
	admin/src/Core/Write/Reverter.php \
	admin/src/Core/Write/RunRecovery.php \
	admin/src/Core/Write/TargetWriter.php \
	admin/src/Core/Write/WriteOutcome.php \
	admin/src/Core/Write/WritePlanner.php \
	admin/src/Core/Write/WriteRun.php \
	admin/src/Environment/JoomlaEnvironmentProvider.php \
	admin/src/Extension/ECSKCCustomisationComponent.php \
	admin/src/Field/TargetStatusField.php \
	admin/src/Model/DashboardModel.php \
	admin/src/Model/RunRequest.php \
	admin/src/Model/TargetFilter.php \
	admin/src/Model/TargetsModel.php \
	admin/src/Model/WriteResult.php \
	admin/src/Operational/JoomlaOperationalCheckProvider.php \
	admin/src/View/Dashboard/HtmlView.php \
	admin/src/View/Targets/HtmlView.php \
	admin/tmpl/dashboard/default.php \
	admin/tmpl/targets/default.php \
	admin/tmpl/targets/default_summary.php \
	admin/tmpl/targets/default_targets.php \
	admin/tmpl/targets/default_writeform.php \
	admin/tmpl/targets/result.php

# The system plugin's files, relative to $(SYSTEM_PLUGIN_ROOT). Linted here;
# the build does not package the plugin yet (ticket 49).
SYSTEM_PLUGIN_FILES := \
	ecskccustom.xml \
	language/en-GB/plg_system_ecskccustom.sys.ini \
	services/provider.php \
	src/Extension/ECSKCCustomisationPlugin.php

# The installer plugin's files, relative to $(INSTALLER_PLUGIN_ROOT). Linted here;
# the build does not package the plugin yet (ticket 49).
INSTALLER_PLUGIN_FILES := \
	ecskccustom.xml \
	language/en-GB/plg_installer_ecskccustom.ini \
	language/en-GB/plg_installer_ecskccustom.sys.ini \
	services/provider.php \
	src/Extension/ECSKCCustomisationPlugin.php

RULE_FILES := \
	10-comprofiler.xml \
	20-helix-ultimate.xml \
	30-nts-kbee.xml \
	40-et-tennis.xml \
	50-site-icons.xml

PAYLOAD_FILES := \
	apple-touch-icon.png.v1 \
	apple-touch-icon-precomposed.png.v1 \
	components/com_comprofiler/plugin/language/cbpaidsubscriptions-en-gb/language.php.v1 \
	components/com_comprofiler/plugin/language/cbpaidsubscriptions-en-gb/override.php.v1 \
	components/com_comprofiler/plugin/user/plug_cbgallery/library/Controller/FolderController.php.v1 \
	components/com_comprofiler/plugin/user/plug_cbgallery/library/Controller/FolderController.php.v2 \
	components/com_kunena/template/nts_kbee/config/config.xml.v1 \
	components/com_kunena/template/nts_kbee/layouts/topic/edit/default.php.v1 \
	components/com_kunena/template/nts_kbee/layouts/topic/edit/default.php.v2 \
	components/com_kunena/template/nts_kbee/layouts/widget/error/default.php.v1 \
	components/com_kunena/template/nts_kbee/layouts/widget/error/default.php.v2 \
	plugins/system/helixultimate/overrides/com_contact/contact/default_form.php.v1 \
	plugins/system/helixultimate/overrides_legacy/com_contact/contact/default_form.php.v1 \
	templates/et_tennis/css/override.css \
	templates/et_tennis/options.xml.v1

# Archive-relative paths handed to jzip. The staged tree is the archive root.
PACKAGE_FILES := \
	LICENSE \
	script.php \
	$(COMPONENT_FILES) \
	$(addprefix admin/rules/,$(RULE_FILES)) \
	$(addprefix admin/payload/,$(PAYLOAD_FILES))

DEPS_CMD ?= composer install
TEST_CMD ?= composer test
JZIP ?= tools/jzip.php
JZIP_MASTER ?= $(HOME)/repos/ys_toolbox/src/jzip/jzip.php
COPY ?= tools/copy_nofollow.php
VALIDATE ?= tools/validate.php
ZIP_LEVEL ?= 0
INSTALL_DIR ?= installation
BUILD_DIR ?= build
RELEASE_NOTES ?= docs/RELEASE.md

# `override` is important. The tag, the ZIP name and the download URL all
# derive from this, and deriving it from the manifest instead of typing it is
# the whole point of `release`. Without `override`, `make release VERSION=x`
# tags a version the manifest never declared.
override VERSION := $(shell awk -F'[<>]' '/<version>/{print $$3; exit}' $(MANIFEST))
ZIP_NAME := $(EXT_NAME)-v$(subst .,-,$(VERSION)).zip
RELEASE_STAGE := $(BUILD_DIR)/release
DEV_STAGE := $(BUILD_DIR)/dev
RELEASE_ZIP := $(INSTALL_DIR)/release/$(ZIP_NAME)
DEV_ZIP := $(INSTALL_DIR)/dev/$(ZIP_NAME)

# $(JZIP) is linted although it does not ship: it is the packager.
LINT_CMD := set -e; \
	cmp -s "$(JZIP)" "$(JZIP_MASTER)" || { echo "FAIL: $(JZIP) has drifted from $(JZIP_MASTER) - fix jzip in ys_toolbox, never in the copy"; exit 1; }; \
	for f in $(filter %.php,$(addprefix $(COM_ROOT)/,$(COMPONENT_FILES)) $(addprefix $(SYSTEM_PLUGIN_ROOT)/,$(SYSTEM_PLUGIN_FILES)) $(addprefix $(INSTALLER_PLUGIN_ROOT)/,$(INSTALLER_PLUGIN_FILES))) $(JZIP) $(COPY) $(VALIDATE) tools/hash.php; do \
		php -l "$$f" >/dev/null || exit 1; \
	done; \
	for f in $(MANIFEST) $(SYSTEM_PLUGIN_ROOT)/ecskccustom.xml $(INSTALLER_PLUGIN_ROOT)/ecskccustom.xml $(COM_ROOT)/admin/access.xml $(COM_ROOT)/admin/config.xml $(COM_ROOT)/admin/forms/filter_targets.xml $(addprefix data/rules/,$(RULE_FILES)); do \
		php -r 'libxml_use_internal_errors(true); if (simplexml_load_file($$argv[1]) === false) { fwrite(STDERR, "FAIL: malformed XML in $$argv[1]\n"); exit(1); }' "$$f" || exit 1; \
	done; \
	echo "lint: ok"

sha256 = $$( { if command -v sha256sum >/dev/null 2>&1; then sha256sum "$(1)"; else shasum -a 256 "$(1)"; fi; } | awk '{print $$1}' | grep -Ex '[0-9a-f]{64}' )

# Package staging directory $(2) into $(1), stamping every entry with epoch $(3).
# The release build refuses to fall back to zip; dist_dev keeps that fallback.
define package
	@set -e; \
	for f in $(PACKAGE_FILES); do \
		if [ ! -f "$(2)/$$f" ]; then \
			echo "FAIL: $$f is in PACKAGE_FILES but not in $(2)/ - the staged tree is incomplete"; \
			exit 1; \
		fi; \
	done; \
	mkdir -p "$(dir $(1))"; \
	archive="$$(cd "$(dir $(1))" && pwd)/$$(basename "$(1)").part"; stamp="$(3)"; \
	rm -f "$(1).part"; \
	if [ -f "$(JZIP)" ]; then \
		php "$(JZIP)" --level=$(ZIP_LEVEL) "$$archive" "$$stamp" "$(2)" $(PACKAGE_FILES) >/dev/null; \
		how="jzip level $(ZIP_LEVEL)"; \
	else \
		echo "WARNING: jzip not found at $(JZIP) - falling back to zip." >&2; \
		echo "WARNING: the package is NOT reproducible - zip stores this machine's mtimes and host metadata." >&2; \
		( cd "$(2)" && zip -q -X -$(ZIP_LEVEL) "$$archive" $(PACKAGE_FILES) ); \
		how="zip fallback"; \
	fi; \
	mv "$(1).part" "$(1)"; \
	sum="$(call sha256,$(1))"; \
	echo "$(1): $(words $(PACKAGE_FILES)) entries, $$(wc -c < "$(1)") bytes, $$how, sha256 $$sum"
endef

.PHONY: info deps test lint validate release dist_release dist_dev clean

info:
	@echo "Extension:       $(EXT_NAME)"
	@echo "Version:         $(VERSION)"
	@echo "Source:          $(PACKAGE_FILES)"
	@echo "Release package: $(RELEASE_ZIP)"
	@echo "Dev package:     $(DEV_ZIP)"
	@echo "Packager:        $(JZIP) (level $(ZIP_LEVEL))"

deps:
	$(DEPS_CMD)

test:
	$(TEST_CMD)

lint:
	@$(LINT_CMD)

validate:
	php "$(VALIDATE)"

# Fill staging directory $(1) from source root $(2). $(2) is the repository
# root for a dev build, or the tag export for a release build. Every copy
# goes through copy_nofollow.php, so a symbolic link in the source is a
# failed build rather than bytes pulled in from outside the tree.
define stage_component
	@set -e; \
	src="$(2)"; dest="$(1)"; \
	for f in $(COMPONENT_FILES); do \
		mkdir -p "$$dest/$$(dirname "$$f")"; \
		php "$(COPY)" "$$src/$(COM_ROOT)/$$f" "$$dest/$$f"; \
	done; \
	php "$(COPY)" "$$src/LICENSE" "$$dest/LICENSE"; \
	php "$(COPY)" "$$src/$(COM_ROOT)/admin/script.php" "$$dest/script.php"; \
	for f in $(RULE_FILES); do \
		mkdir -p "$$dest/admin/rules"; \
		php "$(COPY)" "$$src/data/rules/$$f" "$$dest/admin/rules/$$f"; \
	done; \
	for f in $(PAYLOAD_FILES); do \
		mkdir -p "$$dest/admin/payload/$$(dirname "$$f")"; \
		php "$(COPY)" "$$src/data/payload/$$f" "$$dest/admin/payload/$$f"; \
	done
endef

release:
	@set -e; \
	dirty="$$(git status --porcelain)" || { echo "FAIL: git status failed - cannot confirm the tree is clean"; exit 1; }; \
	if [ -n "$$dirty" ]; then \
		echo "FAIL: working tree is dirty - commit or stash before tagging"; \
		printf '%s\n' "$$dirty"; \
		exit 1; \
	fi
	@if git rev-parse -q --verify "refs/tags/$(VERSION)" >/dev/null; then \
		echo "FAIL: tag $(VERSION) already exists - bump <version> in $(MANIFEST)"; \
		exit 1; \
	fi
	@if [ ! -f "$(RELEASE_NOTES)" ]; then \
		echo "FAIL: $(RELEASE_NOTES) is missing - write the release notes before tagging"; \
		exit 1; \
	fi
	@if ! awk -v v="$(VERSION)" '/^## /{ if ($$2==v) found=1 } END{ exit !found }' "$(RELEASE_NOTES)"; then \
		echo "FAIL: $(RELEASE_NOTES) has no '## $(VERSION)' heading - write the release notes before tagging"; \
		exit 1; \
	fi
	$(MAKE) test
	$(MAKE) lint
	git tag "$(VERSION)"
	@echo "Tagged $(VERSION) - next: make dist_release"

dist_release:
	@if ! git rev-parse -q --verify "refs/tags/$(VERSION)" >/dev/null; then \
		echo "FAIL: no tag $(VERSION) - run 'make release' first"; \
		exit 1; \
	fi
	@set -e; \
	dirty="$$(git status --porcelain)" || { echo "FAIL: git status failed - cannot confirm the tree is clean"; exit 1; }; \
	if [ -n "$$dirty" ]; then \
		echo "FAIL: working tree is dirty - a release must be built from a clean checkout of $(VERSION)"; \
		printf '%s\n' "$$dirty"; \
		exit 1; \
	fi; \
	head="$$(git rev-parse HEAD)"; \
	tagged="$$(git rev-parse "$(VERSION)^{commit}")"; \
	if [ "$$head" != "$$tagged" ]; then \
		echo "FAIL: HEAD is not tag $(VERSION)"; \
		echo "      HEAD $$head"; \
		echo "      tag  $$tagged"; \
		echo "      building here would use this checkout's build inputs against the tag's payload,"; \
		echo "      producing bytes a clean checkout of $(VERSION) cannot reproduce. Check the tag out."; \
		exit 1; \
	fi
	@if [ "$(origin ZIP_LEVEL)" = "command line" ] || [ "$(origin JZIP)" = "command line" ] \
		|| [ "$(origin PACKAGE_FILES)" = "command line" ]; then \
		echo "FAIL: ZIP_LEVEL, JZIP and PACKAGE_FILES decide the published bytes and must come from"; \
		echo "      the tagged Makefile, not the command line. Change them in the repository instead."; \
		exit 1; \
	fi
	@if [ ! -f "$(JZIP)" ]; then \
		echo "FAIL: $(JZIP) is missing - a release package must come from the deterministic"; \
		echo "      packager, never the zip fallback. Restore it before building."; \
		exit 1; \
	fi
	@rm -f "$(RELEASE_ZIP)"
	@rm -rf "$(RELEASE_STAGE)"
	@mkdir -p "$(RELEASE_STAGE)"
	@git -c core.autocrlf=false archive "$(VERSION)" | tar -x -C "$(RELEASE_STAGE)"
	@set -e; \
	exported="$$(awk -F'[<>]' '/<version>/{print $$3; exit}' "$(RELEASE_STAGE)/$(MANIFEST)")"; \
	if [ "$$exported" != "$(VERSION)" ]; then \
		echo "FAIL: tag $(VERSION) exports a manifest declaring $$exported"; \
		echo "      the ZIP name comes from the WORKING TREE manifest,"; \
		echo "      so packaging this would publish one version under another's name - move the tag"; \
		echo "      or fix <version> before rebuilding."; \
		exit 1; \
	fi
	php "$(VALIDATE)" --rules "$(RELEASE_STAGE)/data/rules" --payload "$(RELEASE_STAGE)/data/payload"
	@rm -rf "$(RELEASE_STAGE)/.stage"
	@mkdir -p "$(RELEASE_STAGE)/.stage"
	$(call stage_component,$(RELEASE_STAGE)/.stage,$(RELEASE_STAGE))
	$(call package,$(RELEASE_ZIP),$(RELEASE_STAGE)/.stage,$$(git log -1 --format=%ct "$(VERSION)"))
	@rm -rf "$(RELEASE_STAGE)"

dist_dev:
	@rm -f "$(DEV_ZIP)"
	$(MAKE) validate
	@rm -rf "$(DEV_STAGE)"
	@mkdir -p "$(DEV_STAGE)"
	$(call stage_component,$(DEV_STAGE),.)
	@sed '/<updateservers>/,/<\/updateservers>/d' "$(DEV_STAGE)/$(notdir $(MANIFEST))" > "$(DEV_STAGE)/manifest.tmp" \
		&& mv "$(DEV_STAGE)/manifest.tmp" "$(DEV_STAGE)/$(notdir $(MANIFEST))"
	@set -e; \
	rc=0; grep -q "updateservers" "$(DEV_STAGE)/$(notdir $(MANIFEST))" || rc=$$?; \
	if [ "$$rc" -eq 0 ]; then \
		echo "FAIL: <updateservers> survived in the dev manifest"; \
		exit 1; \
	elif [ "$$rc" -gt 1 ]; then \
		echo "FAIL: cannot read $(DEV_STAGE)/$(notdir $(MANIFEST)) to confirm <updateservers> was stripped"; \
		exit 1; \
	fi
	$(call package,$(DEV_ZIP),$(DEV_STAGE),$$(git log -1 --format=%ct))
	@rm -rf "$(DEV_STAGE)"

clean:
	@rm -rf "$(BUILD_DIR)" "$(INSTALL_DIR)/release" "$(INSTALL_DIR)/dev"

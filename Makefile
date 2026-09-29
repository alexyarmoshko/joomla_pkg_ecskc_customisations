# Reproducible packaging for pkg_ecskc_customisations.

SHELL := /bin/sh

PKG_NAME := pkg_ecskc_customisations
PKG_ROOT := extensions/pkg_ecskc_customisations
MANIFEST := $(PKG_ROOT)/$(PKG_NAME).xml
COM_ROOT := extensions/com_ecskccustom
COM_MANIFEST := $(COM_ROOT)/com_ecskccustom.xml
SYSTEM_PLUGIN_ROOT := extensions/plg_system_ecskccustom
INSTALLER_PLUGIN_ROOT := extensions/plg_installer_ecskccustom
GITHUB_OWNER := alexyarmoshko
GITHUB_REPO := joomla_pkg_ecskc_customisations

# The tracked update descriptor template. It never ships in an
# archive; dist_release writes the publishable copy beside the package ZIP.
UPDATE_TEMPLATE := $(PKG_NAME).update.xml
SHA256_PLACEHOLDER := 0000000000000000000000000000000000000000000000000000000000000000

# Every file whose <version> must equal the package's, and the update
# descriptor template.
VERSIONED_MANIFESTS := \
	$(MANIFEST) \
	$(COM_MANIFEST) \
	$(SYSTEM_PLUGIN_ROOT)/ecskccustom.xml \
	$(INSTALLER_PLUGIN_ROOT)/ecskccustom.xml \
	$(UPDATE_TEMPLATE)

# The component's files, relative to $(COM_ROOT). script.php also ships at the
# archive root because Joomla looks there on install and under admin/ on uninstall.
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
	admin/schema/registry.xsd \
	admin/services/provider.php \
	admin/src/Access/WritePermission.php \
	admin/src/Controller/DisplayController.php \
	admin/src/Controller/TargetsController.php \
	admin/src/Core/Condition/ConditionEvaluator.php \
	admin/src/Core/Condition/ConditionGroup.php \
	admin/src/Core/Condition/ConditionOutcome.php \
	admin/src/Core/Condition/RegexExpression.php \
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
	admin/src/Core/Registry/CommunityBuilderPluginRow.php \
	admin/src/Core/Registry/CommunityBuilderReaderInterface.php \
	admin/src/Core/Registry/CommunityBuilderXmlManifest.php \
	admin/src/Core/Registry/EntryStatus.php \
	admin/src/Core/Registry/ExtensionReaderInterface.php \
	admin/src/Core/Registry/ExtensionRow.php \
	admin/src/Core/Registry/RegistryEntry.php \
	admin/src/Core/Registry/RegistryListState.php \
	admin/src/Core/Registry/RegistryLoader.php \
	admin/src/Core/Registry/RegistryLoadResult.php \
	admin/src/Core/Registry/RegistryPage.php \
	admin/src/Core/Registry/RegistryReadFailure.php \
	admin/src/Core/Registry/RegistryResolution.php \
	admin/src/Core/Registry/RegistryResolver.php \
	admin/src/Core/Registry/RegistryRow.php \
	admin/src/Core/Registry/UpdateRow.php \
	admin/src/Core/Registry/UpdateServerStatus.php \
	admin/src/Core/Registry/UpdateSiteLink.php \
	admin/src/Core/Registry/UpdateStatus.php \
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
	admin/src/Core/State/BackupCatalog.php \
	admin/src/Core/State/BackupEntry.php \
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
	admin/src/Core/Write/ApplyExecutor.php \
	admin/src/Core/Write/FreezeGuard.php \
	admin/src/Core/Write/Freezer.php \
	admin/src/Core/Write/MutationTransaction.php \
	admin/src/Core/Write/RecoveryOutcome.php \
	admin/src/Core/Write/Reverter.php \
	admin/src/Core/Write/RunRecovery.php \
	admin/src/Core/Write/TargetResult.php \
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
	admin/src/Registry/DatabaseRead.php \
	admin/src/Registry/DriverValue.php \
	admin/src/Registry/ExtensionLanguageLoader.php \
	admin/src/Registry/JoomlaCommunityBuilderReader.php \
	admin/src/Registry/JoomlaExtensionReader.php \
	admin/src/View/Dashboard/HtmlView.php \
	admin/src/View/Targets/HtmlView.php \
	admin/tmpl/dashboard/default.php \
	admin/tmpl/targets/default.php \
	admin/tmpl/targets/default_counts.php \
	admin/tmpl/targets/default_rows.php \
	admin/tmpl/targets/default_summary.php \
	admin/tmpl/targets/default_targets.php \
	admin/tmpl/targets/default_writeform.php \
	admin/tmpl/targets/result.php

# The system plugin's files, relative to $(SYSTEM_PLUGIN_ROOT).
SYSTEM_PLUGIN_FILES := \
	ecskccustom.xml \
	language/en-GB/plg_system_ecskccustom.sys.ini \
	services/provider.php \
	src/Extension/ECSKCCustomisationPlugin.php

# The installer plugin's files, relative to $(INSTALLER_PLUGIN_ROOT).
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

# The extension registry the Plugins screen reads, staged from data/ to admin/.
REGISTRY_FILE := registry.xml

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

# Archive-relative paths handed to jzip, one list per archive. Each staged tree
# is its archive's root.
COMPONENT_ARCHIVE_FILES := \
	LICENSE \
	script.php \
	$(COMPONENT_FILES) \
	$(addprefix admin/rules/,$(RULE_FILES)) \
	admin/$(REGISTRY_FILE) \
	$(addprefix admin/payload/,$(PAYLOAD_FILES))

# The child archives in the order the package manifest installs them: the
# component first (see the comment in $(MANIFEST)).
CHILD_ARCHIVES := \
	com_ecskccustom.zip \
	plg_system_ecskccustom.zip \
	plg_installer_ecskccustom.zip

PKG_ARCHIVE_FILES := \
	$(PKG_NAME).xml \
	script.php \
	$(addprefix packages/,$(CHILD_ARCHIVES))

DEPS_CMD ?= composer install
TEST_CMD ?= composer test
# JZIP, COPY, VALIDATE, VERIFY and ZIP_LEVEL decide the package bytes or the checks, so
# the environment cannot set them; dist_release refuses them from the command line.
JZIP := tools/jzip.php
COPY := tools/copy_nofollow.php
VALIDATE := tools/validate.php
VERIFY := tools/verify_package.php
ZIP_LEVEL := 0
INSTALL_DIR ?= installation
BUILD_DIR ?= build
RELEASE_NOTES ?= docs/RELEASE.md

# `override` is important. The tag, the ZIP name and the download URL all
# derive from this, and deriving it from the manifest instead of typing it is
# the whole point of `release`. Without `override`, `make release VERSION=x`
# tags a version the manifest never declared.
override VERSION := $(shell awk -F'[<>]' '/<version>/{print $$3; exit}' $(MANIFEST))
ZIP_NAME := $(PKG_NAME)-v$(subst .,-,$(VERSION)).zip
RELEASE_STAGE := $(BUILD_DIR)/release
DEV_STAGE := $(BUILD_DIR)/dev
RELEASE_ZIP := $(INSTALL_DIR)/release/$(ZIP_NAME)
DEV_ZIP := $(INSTALL_DIR)/dev/$(ZIP_NAME)
UPDATE_ARTIFACT := $(INSTALL_DIR)/release/$(UPDATE_TEMPLATE)
DOWNLOAD_URL := https://github.com/$(GITHUB_OWNER)/$(GITHUB_REPO)/releases/download/$(VERSION)/$(ZIP_NAME)

# $(JZIP) is linted although it does not ship: it is the packager.
LINT_CMD := set -e; \
	for f in $(filter %.php,$(addprefix $(COM_ROOT)/,$(COMPONENT_FILES)) $(addprefix $(SYSTEM_PLUGIN_ROOT)/,$(SYSTEM_PLUGIN_FILES)) $(addprefix $(INSTALLER_PLUGIN_ROOT)/,$(INSTALLER_PLUGIN_FILES))) $(PKG_ROOT)/script.php $(JZIP) $(COPY) $(VALIDATE) $(VERIFY) tools/hash.php; do \
		php -l "$$f" >/dev/null || exit 1; \
	done; \
	for f in $(VERSIONED_MANIFESTS) $(COM_ROOT)/admin/access.xml $(COM_ROOT)/admin/config.xml $(COM_ROOT)/admin/forms/filter_targets.xml $(addprefix data/rules/,$(RULE_FILES)) data/$(REGISTRY_FILE); do \
		php -r 'libxml_use_internal_errors(true); if (simplexml_load_file($$argv[1]) === false) { fwrite(STDERR, "FAIL: malformed XML in $$argv[1]\n"); exit(1); }' "$$f" || exit 1; \
	done; \
	echo "lint: ok"

sha256 = $$( { if command -v sha256sum >/dev/null 2>&1; then sha256sum "$(1)"; else shasum -a 256 "$(1)"; fi; } | awk '{print $$1}' | grep -Ex '[0-9a-f]{64}' )

# Shell fragment: package the files $(3), relative to staging directory $(2),
# into $(1), stamping every entry with the epoch in $$stamp. The caller sets
# stamp once, so every archive of one build shares it. The release build
# refuses to fall back to zip; dist_dev keeps that fallback.
define package
	for f in $(3); do \
		if [ ! -f "$(2)/$$f" ]; then \
			echo "FAIL: $$f is in the file list for $(notdir $(1)) but not in $(2)/ - the staged tree is incomplete"; \
			exit 1; \
		fi; \
	done; \
	mkdir -p "$(dir $(1))"; \
	archive="$$(cd "$(dir $(1))" && pwd)/$$(basename "$(1)").part"; \
	rm -f "$(1).part"; \
	if [ -f "$(JZIP)" ]; then \
		php "$(JZIP)" --level=$(ZIP_LEVEL) "$$archive" "$$stamp" "$(2)" $(3) >/dev/null; \
		how="jzip level $(ZIP_LEVEL)"; \
	else \
		echo "WARNING: jzip not found at $(JZIP) - falling back to zip." >&2; \
		echo "WARNING: the package is NOT reproducible - zip stores this machine's mtimes and host metadata." >&2; \
		( cd "$(2)" && zip -q -X -$(ZIP_LEVEL) "$$archive" $(3) ); \
		how="zip fallback"; \
	fi; \
	mv "$(1).part" "$(1)"; \
	sum="$(call sha256,$(1))"; \
	echo "$(1): $(words $(3)) entries, $$(wc -c < "$(1)") bytes, $$how, sha256 $$sum"
endef

# Package the three staged children under staging root $(1) into its
# pkg/packages/, then package pkg/ into $(2). All four archives take the one
# epoch that shell expression $(3) yields. Then check the payload bytes inside
# $(2) against its own rules, and delete $(2) if they differ.
define build_package
	@set -e; \
	stamp="$(3)"; \
	$(call package,$(1)/pkg/packages/com_ecskccustom.zip,$(1)/com,$(COMPONENT_ARCHIVE_FILES)); \
	$(call package,$(1)/pkg/packages/plg_system_ecskccustom.zip,$(1)/plg_system,$(SYSTEM_PLUGIN_FILES)); \
	$(call package,$(1)/pkg/packages/plg_installer_ecskccustom.zip,$(1)/plg_installer,$(INSTALLER_PLUGIN_FILES)); \
	$(call package,$(2),$(1)/pkg,$(PKG_ARCHIVE_FILES)); \
	php "$(VERIFY)" "$(2)" || { rm -f "$(2)"; echo "FAIL: $(2) deleted - its payload bytes do not match its rules"; exit 1; }
endef

# Refuse unless every manifest under source root $(1) declares $(VERSION), which
# comes from the working-tree package manifest. For a release $(1) is the tag
# export, so a tag whose package manifest disagrees with the working tree fails
# here too: packaging it would publish one version under another's name.
define check_versions
	@set -e; \
	for m in $(VERSIONED_MANIFESTS); do \
		declared="$$(awk -F'[<>]' '/<version>/{print $$3; exit}' "$(1)/$$m")"; \
		if [ "$$declared" != "$(VERSION)" ]; then \
			echo "FAIL: $(1)/$$m declares version '$$declared', but the package is $(VERSION) from $(MANIFEST)"; \
			echo "      every manifest in the package and the update descriptor template must declare"; \
			echo "      the package manifest's version"; \
			exit 1; \
		fi; \
	done
endef

# Delete <updateservers> from staged manifest $(1), and fail if any survives.
# A dev package must not register the release update site.
define strip_updateservers
	@set -e; \
	sed '/<updateservers>/,/<\/updateservers>/d' "$(1)" > "$(1).tmp"; \
	mv "$(1).tmp" "$(1)"; \
	rc=0; grep -q "updateservers" "$(1)" || rc=$$?; \
	if [ "$$rc" -eq 0 ]; then \
		echo "FAIL: <updateservers> survived in $(1)"; \
		exit 1; \
	elif [ "$$rc" -gt 1 ]; then \
		echo "FAIL: cannot read $(1) to confirm <updateservers> was stripped"; \
		exit 1; \
	fi
endef

# The only variables release and dist_release take from the command line: where
# dist_release writes. Every other variable and macro decides the published
# bytes, the version or which checks run, so it must come from the Makefile.
override RELEASE_FREE := INSTALL_DIR BUILD_DIR

# Variables set on the command line, passed down from a parent make, or taken
# from the environment under make -e, less RELEASE_FREE. Declared override so
# release_overrides= on the command line cannot empty it.
override release_overrides = $(sort $(filter-out $(RELEASE_FREE),$(foreach v,$(.VARIABLES),$(if $(findstring command line,$(origin $(v)))$(findstring environment override,$(origin $(v))),$(v)))))

# The first step of release and dist_release: refuse any release_overrides.
# Declared override so the command line cannot replace it either.
override define refuse_release_overrides
	@if [ -n "$(release_overrides)" ]; then \
		echo "FAIL: $(release_overrides) set outside the Makefile"; \
		echo "      a release takes only $(RELEASE_FREE) from the command line; everything else"; \
		echo "      must come from the tagged Makefile. Change it in the repository instead."; \
		exit 1; \
	fi
endef

# Refuse unless $(UPDATE_TEMPLATE) is still a template: exactly one <sha256>,
# and that one the placeholder. A real checksum committed there would describe
# one old ZIP and be published against every later one.
define check_update_template
	@set -e; \
	placeholders="$$(grep -oF "<sha256>$(SHA256_PLACEHOLDER)</sha256>" "$(UPDATE_TEMPLATE)" | wc -l)"; \
	sums="$$(grep -oF "<sha256>" "$(UPDATE_TEMPLATE)" | wc -l)"; \
	if [ "$$placeholders" -ne 1 ] || [ "$$sums" -ne 1 ]; then \
		echo "FAIL: $(UPDATE_TEMPLATE) is not a template - it must hold exactly one <sha256>, the $(SHA256_PLACEHOLDER) placeholder"; \
		exit 1; \
	fi
endef

.PHONY: info deps test lint validate versions release dist_release dist_dev update_manifest clean

info:
	@echo "Package:         $(PKG_NAME)"
	@echo "Version:         $(VERSION)"
	@echo "Child archives:  $(CHILD_ARCHIVES)"
	@echo "Release package: $(RELEASE_ZIP)"
	@echo "Dev package:     $(DEV_ZIP)"
	@echo "Update template: $(UPDATE_TEMPLATE)"
	@echo "Update artifact: $(UPDATE_ARTIFACT)"
	@echo "Download URL:    $(DOWNLOAD_URL)"
	@echo "Packager:        $(JZIP) (level $(ZIP_LEVEL))"

deps:
	$(DEPS_CMD)

test:
	$(TEST_CMD)

lint:
	@$(LINT_CMD)

validate:
	php "$(VALIDATE)" --registry data/$(REGISTRY_FILE)

# Check the manifests under VERSION_ROOT, the repository unless given.
VERSION_ROOT ?= .
versions:
	$(call check_versions,$(VERSION_ROOT))
	@echo "versions: every manifest declares $(VERSION)"

# Fill component staging directory $(1) from source root $(2). $(2) is the repository
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
	php "$(COPY)" "$$src/data/$(REGISTRY_FILE)" "$$dest/admin/$(REGISTRY_FILE)"; \
	for f in $(PAYLOAD_FILES); do \
		mkdir -p "$$dest/admin/payload/$$(dirname "$$f")"; \
		php "$(COPY)" "$$src/data/payload/$$f" "$$dest/admin/payload/$$f"; \
	done
endef

# Copy the files $(3), relative to source directory $(2), into staging
# directory $(1), through copy_nofollow.php as above.
define stage_files
	@set -e; \
	for f in $(3); do \
		mkdir -p "$(1)/$$(dirname "$$f")"; \
		php "$(COPY)" "$(2)/$$f" "$(1)/$$f"; \
	done
endef

# Stage 1's output, laid out for stages 2 and 3: one staging directory per
# child archive under $(1), and pkg/ for the package's own manifest and script.
define stage_package
	$(call stage_component,$(1)/com,$(2))
	$(call stage_files,$(1)/plg_system,$(2)/$(SYSTEM_PLUGIN_ROOT),$(SYSTEM_PLUGIN_FILES))
	$(call stage_files,$(1)/plg_installer,$(2)/$(INSTALLER_PLUGIN_ROOT),$(INSTALLER_PLUGIN_FILES))
	$(call stage_files,$(1)/pkg,$(2)/$(PKG_ROOT),$(PKG_NAME).xml script.php)
endef

release:
	$(refuse_release_overrides)
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
	$(call check_versions,.)
	$(check_update_template)
	$(MAKE) test
	$(MAKE) lint
	git tag "$(VERSION)"
	@echo "Tagged $(VERSION) - next: make dist_release"

dist_release:
	$(refuse_release_overrides)
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
	@if [ ! -f "$(JZIP)" ]; then \
		echo "FAIL: $(JZIP) is missing - a release package must come from the deterministic"; \
		echo "      packager, never the zip fallback. Restore it before building."; \
		exit 1; \
	fi
	@rm -f "$(RELEASE_ZIP)" "$(UPDATE_ARTIFACT)"
	@rm -rf "$(RELEASE_STAGE)"
	@mkdir -p "$(RELEASE_STAGE)"
	@git -c core.autocrlf=false archive "$(VERSION)" | tar -x -C "$(RELEASE_STAGE)"
	$(call check_versions,$(RELEASE_STAGE))
	php "$(VALIDATE)" --rules "$(RELEASE_STAGE)/data/rules" --payload "$(RELEASE_STAGE)/data/payload" --registry "$(RELEASE_STAGE)/data/$(REGISTRY_FILE)"
	@rm -rf "$(RELEASE_STAGE)/.stage"
	@mkdir -p "$(RELEASE_STAGE)/.stage"
	$(call stage_package,$(RELEASE_STAGE)/.stage,$(RELEASE_STAGE))
	$(call build_package,$(RELEASE_STAGE)/.stage,$(RELEASE_ZIP),$$(git log -1 --format=%ct "$(VERSION)"))
	@rm -rf "$(RELEASE_STAGE)"
	$(MAKE) update_manifest

# Write $(UPDATE_ARTIFACT) from $(UPDATE_TEMPLATE) for the ZIP at $(RELEASE_ZIP).
# dist_release runs this last. The template is read from the
# checkout, which dist_release has already proved is the clean tag.
update_manifest:
	$(refuse_release_overrides)
	$(check_update_template)
	@set -e; \
	if [ ! -f "$(RELEASE_ZIP)" ]; then \
		echo "FAIL: $(RELEASE_ZIP) is missing - the descriptor must describe a built package"; \
		exit 1; \
	fi; \
	SHA256="$(call sha256,$(RELEASE_ZIP))"; \
	if [ -z "$$SHA256" ]; then \
		echo "FAIL: cannot compute the sha256 of $(RELEASE_ZIP)"; \
		exit 1; \
	fi; \
	mkdir -p "$(dir $(UPDATE_ARTIFACT))"; \
	awk -v url="$(DOWNLOAD_URL)" -v sha="$$SHA256" '{ \
		if ($$0 ~ /<downloadurl[^>]*>[^<]+<\/downloadurl>/) { \
			sub(/<downloadurl[^>]*>[^<]+<\/downloadurl>/, "<downloadurl type=\"full\" format=\"zip\">" url "</downloadurl>"); \
		} else if ($$0 ~ /<sha256>[^<]+<\/sha256>/) { \
			sub(/<sha256>[^<]+<\/sha256>/, "<sha256>" sha "</sha256>"); \
		} \
		print; \
	}' "$(UPDATE_TEMPLATE)" > "$(UPDATE_ARTIFACT).part"; \
	if [ "$$(grep -oF "<sha256>" "$(UPDATE_ARTIFACT).part" | wc -l)" -ne 1 ] \
		|| [ "$$(grep -oF "<downloadurl" "$(UPDATE_ARTIFACT).part" | wc -l)" -ne 1 ]; then \
		echo "FAIL: $(UPDATE_ARTIFACT) must carry exactly one <sha256> and one <downloadurl>"; \
		rm -f "$(UPDATE_ARTIFACT).part"; \
		exit 1; \
	fi; \
	if [ "$$(grep -oF "<sha256>$$SHA256</sha256>" "$(UPDATE_ARTIFACT).part" | wc -l)" -ne 1 ]; then \
		echo "FAIL: $(UPDATE_ARTIFACT) does not carry the sha256 of $(RELEASE_ZIP)"; \
		rm -f "$(UPDATE_ARTIFACT).part"; \
		exit 1; \
	fi; \
	if [ "$$(grep -oF '<downloadurl type="full" format="zip">$(DOWNLOAD_URL)</downloadurl>' "$(UPDATE_ARTIFACT).part" | wc -l)" -ne 1 ]; then \
		echo "FAIL: $(UPDATE_ARTIFACT) does not carry the download URL for $(VERSION)"; \
		rm -f "$(UPDATE_ARTIFACT).part"; \
		exit 1; \
	fi; \
	if [ "$$(awk -F'[<>]' '/<version>/{print $$3; exit}' "$(UPDATE_ARTIFACT).part")" != "$(VERSION)" ]; then \
		echo "FAIL: $(UPDATE_ARTIFACT) declares a version other than $(VERSION)"; \
		rm -f "$(UPDATE_ARTIFACT).part"; \
		exit 1; \
	fi; \
	if [ "$$(grep -oF '<element>$(PKG_NAME)</element>' "$(UPDATE_ARTIFACT).part" | wc -l)" -ne 1 ] \
		|| [ "$$(grep -oF '<type>package</type>' "$(UPDATE_ARTIFACT).part" | wc -l)" -ne 1 ] \
		|| [ "$$(grep -oF '<client>site</client>' "$(UPDATE_ARTIFACT).part" | wc -l)" -ne 1 ]; then \
		echo "FAIL: $(UPDATE_ARTIFACT) must describe element $(PKG_NAME), type package, client site"; \
		rm -f "$(UPDATE_ARTIFACT).part"; \
		exit 1; \
	fi; \
	mv "$(UPDATE_ARTIFACT).part" "$(UPDATE_ARTIFACT)"; \
	if [ "$(call sha256,$(RELEASE_ZIP))" != "$$SHA256" ]; then \
		echo "FAIL: $(RELEASE_ZIP) changed while $(UPDATE_ARTIFACT) was written"; \
		rm -f "$(UPDATE_ARTIFACT)"; \
		exit 1; \
	fi; \
	echo "Wrote $(UPDATE_ARTIFACT): $(DOWNLOAD_URL), sha256 $$SHA256"

dist_dev:
	@rm -f "$(DEV_ZIP)"
	$(MAKE) validate
	$(call check_versions,.)
	@rm -rf "$(DEV_STAGE)"
	@mkdir -p "$(DEV_STAGE)"
	$(call stage_package,$(DEV_STAGE),.)
	$(call strip_updateservers,$(DEV_STAGE)/pkg/$(PKG_NAME).xml)
	$(call strip_updateservers,$(DEV_STAGE)/com/com_ecskccustom.xml)
	$(call strip_updateservers,$(DEV_STAGE)/plg_system/ecskccustom.xml)
	$(call strip_updateservers,$(DEV_STAGE)/plg_installer/ecskccustom.xml)
	$(call build_package,$(DEV_STAGE),$(DEV_ZIP),$$(git log -1 --format=%ct))
	@rm -rf "$(DEV_STAGE)"

clean:
	@rm -rf "$(BUILD_DIR)" "$(INSTALL_DIR)/release" "$(INSTALL_DIR)/dev"

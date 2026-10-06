<?php

declare(strict_types=1);

require __DIR__ . "/../tests/CorePackageSmokeTest.php";
require __DIR__ . "/../tests/QueueApiCompatibilityTest.php";
require __DIR__ . "/../tests/LegacyQueueCapabilityTest.php";
require __DIR__ . "/../tests/RuntimeHardeningTest.php";
require __DIR__ . "/../tests/RuntimeDeveloperToolsTest.php";
require __DIR__ . "/../tests/UploadFilesystemHardeningTest.php";
require __DIR__ . "/../tests/ConcurrencyHardeningTest.php";
require __DIR__ . "/../tests/ProductSpecificationContractTest.php";
require __DIR__ . "/../tests/ProductValidatorTest.php";
require __DIR__ . "/../tests/ProductModuleLifecycleTest.php";
require __DIR__ . "/../tests/SecurityPrimitivesTest.php";
require __DIR__ . "/../tests/ActionEventFlowTest.php";
require __DIR__ . "/../tests/CapabilityArchitectureTest.php";
require __DIR__ . "/../tests/AuditHardeningTest.php";
require __DIR__ . "/../tests/ResilienceTest.php";
require __DIR__ . "/../tests/ResilienceExportTest.php";
[$atomicExit, $atomicOutput] = run_process([PHP_BINARY, "tests/ProductModuleStateAtomicityTest.php"], dirname(__DIR__));
assert_same(0, $atomicExit, "Product Module state atomicity failed: " . $atomicOutput);
echo $atomicOutput;
require __DIR__ . "/../tests/FnllaUpgradeCommandTest.php";
require __DIR__ . "/../tests/ReleaseArtifactBuilderTest.php";

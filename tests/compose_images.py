"""Verify resolved Compose images using dummy settings. No services are started."""
import json
import os
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ("compose.yaml", "compose.test.yaml")
BUILD = ("compose.setup.yaml",)


def services(files, **overrides):
    settings = dict(os.environ)
    for name in ("RELEASE_TAG", "HEJI_POSTGRES_IMAGE", "HEJI_REDIS_IMAGE"):
        settings.pop(name, None)
    settings.update(
        HEJI_ENV_FILE=str(ROOT / ".env.example"),
        HEJI_VAR="/tmp/heji-compose-check",
        DB_OWNER_PASSWORD="compose-check",
        DB_PASSWORD="compose-check",
        REDIS_PASSWORD="compose-check",
    )
    settings.update(overrides)
    command = ["docker", "compose", "--env-file", "/dev/null", "--profile", "*"]
    for name in files:
        command.extend(["-f", name])
    result = subprocess.check_output(command + ["config", "--format", "json"], cwd=ROOT, env=settings)
    return json.loads(result)["services"]


class ImageTags(unittest.TestCase):
    def test_default_images_use_latest(self):
        services_by_name = services(RUNTIME)
        for name, image in {
            "db": "postgres", "redis": "redis", "app": "heji-app", "scheduler": "heji-app",
            "logs": "heji-logs", "migrate": "heji-app", "web": "heji-web", "tests": "heji-test", "browser": "heji-browser",
        }.items():
            self.assertEqual(services_by_name[name]["image"], image + ":latest", name)
        build = services(BUILD)
        self.assertEqual(build["files"]["image"], "alpine:latest")
        for name in ("app", "web", "test", "browser", "logs"):
            self.assertEqual(build[name + "-image"]["image"], "heji-" + name + ":latest")

    def test_only_operations_has_engine_access_and_shared_host_paths(self):
        build = services(BUILD)
        runtime = services(RUNTIME)
        ops = build["ops"]
        self.assertEqual(ops["build"]["target"], "operations")
        self.assertEqual(ops["network_mode"], "host")
        mounts = {v["target"]: v for v in ops["volumes"]}
        source = ops["environment"]["HEJI_SOURCE_DIR"]
        config = ops["environment"]["HEJI_CONFIG_DIR"]
        self.assertEqual(source, "/project")
        self.assertEqual(mounts[source]["source"], str(ROOT))
        self.assertTrue(mounts[source]["read_only"])
        self.assertEqual(mounts[config]["source"], config)
        self.assertIn("/var/run/docker.sock", mounts)
        for service in [*runtime.values(), *(v for k, v in build.items() if k != "ops")]:
            self.assertFalse(any(v.get("source") == "/var/run/docker.sock" for v in service.get("volumes", [])))
        self.assertEqual(runtime["logs"]["network_mode"], "none")
        for name in ("app", "web", "scheduler", "migrate", "db", "redis", "tests", "browser"):
            self.assertEqual(runtime[name]["logging"]["driver"], "fluentd")
            self.assertEqual(runtime[name]["logging"]["options"]["fluentd-address"],
                             "unix:///tmp/heji-compose-check/log-socket/forward.sock")

    def test_release_builds_and_runs_the_same_images(self):
        tag = "candidate-check"
        postgres = "postgres@sha256:" + "1" * 64
        redis = "redis@sha256:" + "2" * 64
        build = services(BUILD, RELEASE_TAG=tag)
        runtime = services(RUNTIME, RELEASE_TAG=tag, HEJI_POSTGRES_IMAGE=postgres, HEJI_REDIS_IMAGE=redis)
        self.assertEqual(runtime["db"]["image"], postgres)
        self.assertEqual(runtime["redis"]["image"], redis)
        for service, target in [("app", "app"), ("scheduler", "app"), ("migrate", "app"), ("web", "web"),
                                ("tests", "test"), ("browser", "browser"), ("logs", "logs")]:
            image = "heji-" + target + ":" + tag
            self.assertEqual(build[target + "-image"]["image"], image)
            self.assertEqual(runtime[service]["image"], image)
            self.assertEqual(runtime[service]["pull_policy"], "never")


if __name__ == "__main__":
    unittest.main(verbosity=2)

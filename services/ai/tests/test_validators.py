import logging

import pytest

from app.models import GenerateRequest


def test_temperature_range_valid():
    req = GenerateRequest(model="test", prompt="test", temperature=0.7)
    assert req.temperature == 0.7


def test_temperature_range_invalid():
    with pytest.raises(ValueError, match="between 0.0 and 1.0"):
        GenerateRequest(model="test", prompt="test", temperature=1.5)


def test_top_p_range_valid():
    req = GenerateRequest(model="test", prompt="test", top_p=0.9)
    assert req.top_p == 0.9


def test_top_p_range_invalid():
    with pytest.raises(ValueError, match="between 0.0 and 1.0"):
        GenerateRequest(model="test", prompt="test", top_p=-0.1)


def test_both_temperature_and_top_p_logs_warning(caplog):
    caplog.set_level(logging.WARNING)
    req = GenerateRequest(
        model="test",
        prompt="test",
        temperature=0.7,
        top_p=0.9,
    )
    assert "Both temperature" in caplog.text
    assert req.temperature == 0.7
    assert req.top_p == 0.9

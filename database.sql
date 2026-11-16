<?php

// Arquivo criado em 16/11/2026 08:54
CREATE DATABASE IF NOT EXISTS global_exchange CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE global_exchange;

-- Tabela para armazenar o cache das taxas de câmbio da API
CREATE TABLE IF NOT EXISTS exchange_rates_cache (
    base_currency VARCHAR(10) PRIMARY KEY,
    rates_json LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL
);

-- Tabela para salvar o histórico de conversões feitas pelos usuários
CREATE TABLE IF NOT EXISTS conversion_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    amount DECIMAL(15, 2) NOT NULL,
    from_currency VARCHAR(10) NOT NULL,
    to_currency VARCHAR(10) NOT NULL,
    converted_amount DECIMAL(15, 4) NOT NULL,
    exchange_rate DECIMAL(15, 6) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

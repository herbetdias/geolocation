# GLPI Geolocation Plugin

Plugin para GLPI 10/11 que adiciona geolocalização aos assets do tipo **Computador**, detectando automaticamente a localização a partir do IP do agente.

## Prints

<img width="2517" height="802" alt="image" src="https://github.com/user-attachments/assets/c5f23468-cfb6-4f98-b693-b016a390e6c4" />
<img width="1936" height="590" alt="image" src="https://github.com/user-attachments/assets/a3409704-ab76-4420-aad2-90cd4601490e" />


## Funcionalidades

- **Aba Geolocation** em cada asset Computador com mapa interativo (OpenStreetMap)
- **Detecção automática** de localização via IP do agente (ip-api.com)
- **Fallback para IP público** quando o agente reporta IP privado (redes Docker, NAT, etc.)
- **Geocodificação reversa** via OpenStreetMap/Nominatim para obter endereço detalhado
- **Auto-resolve** na chegada de inventário (hook em ITEM_UPDATE e ITEM_ADD)
- **Bulk resolve** para resolver localização de todos os computadores de uma vez
- **Links diretos** para OpenStreetMap e Google Maps
- **Painel de configuração** com estatísticas e toggle de auto-resolve

## Dados exibidos

| Campo | Descrição |
|-------|-----------|
| IP Address | IP do agente (com nota de fallback se usado IP público) |
| Address | Endereço resumido |
| Neighborhood | Bairro / Distrito |
| City | Cidade |
| State | Estado / Região |
| Country | País |
| ZIP | CEP |
| ISP | Provedor de internet |
| Coordinates | Latitude e Longitude |

## Requisitos

- GLPI >= 10.0.0 e <= 11.x
- PHP >= 8.0
- Acesso HTTP de saída para `ip-api.com` e `nominatim.openstreetmap.org`
- (Opcional) Acesso a `api.ipify.org` para fallback de IP público

## Instalacao

1. Copie a pasta `geolocation` para `plugins/` do seu GLPI:

```
cp -r geolocation /var/www/glpi/plugins/
```

2. Instale e ative via CLI:

```bash
php bin/console plugin:install geolocation -u glpi
php bin/console plugin:activate geolocation
```

Ou ative pela interface: **Configuracao > Plugins > Geolocation > Instalar > Ativar**

## Uso

### Aba no Computador
Abra qualquer asset **Computador** e clique na aba **Geolocation**. Se ainda nao houver dados, clique em **"Resolve Location"**.

### Geocodificacao reversa
Apos resolver a localizacao, o botao **"Get Street Address"** aparece. Ele consulta o OpenStreetMap para obter o endereco completo (rua, bairro, CEP).

### Configuracao
Acesse **Configuracao > Geolocation** para:
- Ativar/desativar auto-resolve no inventario
- Executar bulk resolve em todos os computadores
- Ver estatisticas (total resolvido, pendentes, falhas)

## APIs utilizadas

| API | Uso | Limite |
|-----|-----|--------|
| [ip-api.com](http://ip-api.com) | Geolocalizacao por IP | 45 req/min (gratuito) |
| [Nominatim/OSM](https://nominatim.openstreetmap.org) | Geocodificacao reversa | 1 req/seg (gratuito) |
| [api.ipify.org](https://api.ipify.org) | Deteccao de IP publico (fallback) | Ilimitado |

## Estrutura

```
geolocation/
├── setup.php              # Registro do plugin e hooks
├── hook.php               # Criacao de tabelas e hooks de inventario
├── inc/
│   └── computer.class.php # Logica de geolocalizacao e aba no Computer
├── front/
│   ├── config.php         # Pagina de configuracao
│   └── resolve.php        # Handler dos botoes de resolve
└── public/                # Assets estaticos (se houver)
```

## Tabelas criadas

- `glpi_plugin_geolocation_computers` — Dados de localizacao por computador
- `glpi_plugin_geolocation_configs` — Configuracoes do plugin

## Licenca

GPLv3

# Track123 Tracking API 集成参考（模块实况版）

> 面向 `Pynarae_Tracking` 模块维护者。本文重点说明“本模块实际会传什么参数”，用于排查注册失败。

## 1. 注册接口（Register trackings）

- Method: `POST`
- URL: `{base_url}/track/import`
- 默认 `base_url`: `https://api.track123.com/gateway/open-api/tk/v2.1`
- Header:
  - `Track123-Api-Secret: <API Secret>`
  - `Accept: application/json`
  - `Content-Type: application/json`

## 2. 本模块注册时的 body 结构

本模块发的是 **数组**（支持批量），每个元素一个运单对象。当前实现下，至少会传：

- `trackNo`：运单号（Magento Shipment Track number）
- `orderNo`：Magento 订单号（increment id）

可选字段：

- `courierCode`：若本地映射可解析，或启用自动识别后识别成功，则会附带
- `postalCode`：需要附加验证时由订单地址或手工输入注入
- `phoneSuffix`：需要附加验证时由订单电话后缀或手工输入注入
- `extendFieldMap.phoneSuffix`：与 `phoneSuffix` 一并注入，兼容 Track123 附加字段场景

### 示例（本模块可能发出的请求）

```json
[
  {
    "trackNo": "771700723045",
    "orderNo": "100000123",
    "courierCode": "fedex",
    "postalCode": "94107",
    "phoneSuffix": "2390",
    "extendFieldMap": {
      "phoneSuffix": "2390"
    }
  }
]
```

## 3. 常见注册失败排查清单

1. `base_url` 版本错误（应与账号可用版本一致，默认 v2.1）。
2. `Track123-Api-Secret` 未配置或配置在错误 store scope。
3. `courierCode` 错误或与运单号不匹配。
4. 命中附加验证要求，但订单缺少 `postalCode` / `phoneSuffix`。
5. `orderNo` 重复导入（可能被 Track123 拒绝或视为重复）。

## 4. 与模块代码的对应关系

- 注册入口：`Model/TrackingSynchronizer::registerTrack()`
- 注册请求发送：`Model/Track123Client::registerTrackings()`
- URL 拼接与请求头：`Model/Track123Client::post()`
- 附加验证字段注入：
  - `Model/Track123Client::injectVerificationIntoTrackingPayload()`
  - `Model/VerificationContextResolver::forOrder()`



## 5. Detection 请求字段兼容说明

- 插件现已按 `tracking_number` 优先调用 `/courier/detection`，并保留对旧字段 `trackNo` 的回退尝试，兼容不同版本网关字段差异。
- 如 detection 失败，注册流程仍会继续（仅不附带 `courierCode`），避免因识别失败直接阻断注册。

## 6. 如何查看注册请求参数（脱敏日志）

- 当 `pynarae_tracking/general/debug = 1` 时，插件会记录 `Track123 request prepared` 与 `Track123 request success`，包含请求 `url` 与**脱敏后的 payload**。
- 脱敏字段包括：`trackNo`、`tracking_number`、`orderNo`、`trackNos`、`orderNos`、`postalCode`、`phoneSuffix`、`customerEmail`。

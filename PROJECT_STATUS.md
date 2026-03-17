# 온라인 판매 자동화 시스템
## 쿠팡 주문 자동화 + 배송 상태 변경 API 설계 문서

---

# 1. 프로젝트 개요

본 시스템은 **쿠팡 주문을 자동 수집 → 정규화 → 택배 송장 생성 → 배송 상태 변경**까지 자동 처리하는 시스템이다.

목표

쿠팡 주문 처리 전체 자동화

```
주문 수집
→ 주문 정규화
→ 택배사 업로드 파일 생성
→ 송장번호 수집
→ 쿠팡 상품준비중 처리
→ 쿠팡 배송중 처리
```

최종 목표

**쿠팡 주문 처리 자동화 100%**

---

# 2. 개발 환경

## OS

Windows (공장 PC)

## Web Server

Apache (Laragon)

## Language

PHP 8.x

## Database

MySQL

## DB Access

PDO

## 관리자 UI

모바일 우선 관리자 페이지

```
/ship_new/public/x9k3admin/
```

## 외부 연동

쿠팡 OpenAPI  
로젠택배 엑셀 업로드

추후 예정

네이버 스마트스토어 API

---

# 3. 시스템 전체 흐름

```
쿠팡 API
↓
collect_orders
↓
orders_raw 저장
↓
normalize_coupang
↓
coupang_order_excel 저장
↓
shipmentBoxId 수집
↓
쿠팡 상품준비중 API
↓
로젠 택배 엑셀 생성
↓
로젠 택배 업로드
↓
송장번호 다운로드
↓
송장번호 DB 반영
↓
쿠팡 배송중 API
```

---

# 4. 프로젝트 디렉토리 구조

```
ship_new
│
├ app
│ ├ jobs
│ │ ├ collect_orders.php
│ │ ├ normalize_coupang.php
│ │ ├ make_lozen_file.php
│ │ ├ import_lozen_invoice.php
│ │ ├ coupang_prepare.php
│ │ └ coupang_ship.php (예정)
│ │
│ ├ services
│ │ └ coupang_api.php
│ │
│ ├ bootstrap.php
│ ├ db.php
│ └ job_runner.php
│
├ public
│ └ x9k3admin
│
└ storage
  ├ lozen_excel
  └ invoice
```

---

# 5. DB 구조

---

# 5.1 orders_raw

쿠팡 API에서 수집한 **원본 데이터를 저장하는 테이블**

```sql
CREATE TABLE orders_raw (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

 platform ENUM('coupang','smartstore'),

 order_no VARCHAR(80),

 raw_json JSON,

 ordered_at DATETIME,

 created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

 is_normalized TINYINT DEFAULT 0,

 normalized_at DATETIME
);
```

설명

|컬럼|설명|
|---|---|
id|PK
platform|플랫폼 구분
order_no|주문번호
raw_json|쿠팡 API 원본 JSON
ordered_at|주문시간
is_normalized|정규화 여부
normalized_at|정규화 완료시간

---

# 5.2 coupang_order_excel

정규화된 주문 데이터를 저장하는 테이블

```sql
CREATE TABLE coupang_order_excel (

 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

 order_no VARCHAR(80),

 option_id VARCHAR(50),

 qty INT,

 ordered_at DATETIME,

 buyer_name VARCHAR(100),
 buyer_phone VARCHAR(50),

 receiver_name VARCHAR(100),
 receiver_phone VARCHAR(50),

 zipcode VARCHAR(20),
 receiver_address TEXT,

 delivery_message TEXT,

 carrier_name VARCHAR(50),

 tracking_no VARCHAR(100),

 shipment_box_id BIGINT,

 lozen_exported_at DATETIME,

 lozen_uploaded_at DATETIME,

 source_file VARCHAR(100),

 created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

 UNIQUE KEY uniq_order (order_no,option_id)
);
```

설명

|컬럼|설명|
|---|---|
order_no|orderItemId
option_id|vendorItemId
qty|수량
shipment_box_id|shipmentBoxId
tracking_no|송장번호
carrier_name|택배사

---

# 6. 쿠팡 주문 데이터 구조

쿠팡 API 주문 JSON 구조

```json
{
 "orderId":27100176170648,
 "shipmentBoxId":664050744442945,
 "orderItems":[
  {
   "orderItemId":123456,
   "vendorItemId":73698092858,
   "shippingCount":1
  }
 ]
}
```

핵심 ID 구조

|ID|설명|
|---|---|
orderId|주문 전체 ID
orderItemId|상품 주문 단위 ID
vendorItemId|상품 옵션 ID
shipmentBoxId|배송 묶음 ID

---

# 7. 시스템 내부 매핑 구조

|DB 컬럼|쿠팡 값|
|---|---|
order_no|orderItemId
option_id|vendorItemId
shipment_box_id|shipmentBoxId

---

# 8. Normalize 처리

목적

쿠팡 JSON → 내부 DB 구조 변환

처리 과정

```
orders_raw 조회
↓
JSON 파싱
↓
coupang_order_excel INSERT
↓
shipmentBoxId 수집
↓
상품준비중 API 호출
```

---

# 9. shipmentBoxId 수집

중복 제거 처리

```php
$prepareIds = [];

if ($shipmentBoxId) {
 $prepareIds[$shipmentBoxId] = $shipmentBoxId;
}
```

---

# 10. 쿠팡 API 호출 제한

쿠팡 API

shipmentBoxIds

최대 **50개**

따라서 분할 호출 필요

```php
$chunks = array_chunk(array_values($prepareIds),50);

foreach ($chunks as $ids) {
 run_coupang_prepare($jobId,$ids);
}
```

---

# 11. 쿠팡 상품준비중 API

Endpoint

```
PUT
/v2/providers/openapi/apis/api/v4/vendors/{vendorId}/ordersheets/acknowledgement
```

Request Body

```json
{
 "vendorId":"A00180903",
 "shipmentBoxIds":[664050744442945]
}
```

---

# 12. 상품준비중 API 코드

파일

```
app/jobs/coupang_prepare.php
```

```php
<?php
declare(strict_types=1);

require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../job_runner.php';

function run_coupang_prepare(int $jobId,array $shipmentBoxIds): void
{
 if(!$shipmentBoxIds) return;

 $env=envv('APP_ENV','local');
 $vendorId=envv('COUPANG_VENDOR_ID');

 if($env==='prod'){
  $accessKey=envv('COUPANG_ACCESS_KEY_PROD');
  $secretKey=envv('COUPANG_SECRET_KEY_PROD');
 }else{
  $accessKey=envv('COUPANG_ACCESS_KEY_DEV');
  $secretKey=envv('COUPANG_SECRET_KEY_DEV');
 }

 $path="/v2/providers/openapi/apis/api/v4/vendors/{$vendorId}/ordersheets/acknowledgement";

 $method="PUT";

 $datetime=gmdate("ymd").'T'.gmdate("His").'Z';

 $message=$datetime.$method.$path;

 $signature=hash_hmac('sha256',$message,$secretKey);

 $authorization=
 "CEA algorithm=HmacSHA256, access-key={$accessKey}, signed-date={$datetime}, signature={$signature}";

 $url="https://api-gateway.coupang.com{$path}";

 $body=json_encode([
  "vendorId"=>$vendorId,
  "shipmentBoxIds"=>$shipmentBoxIds
 ]);

 $ch=curl_init();

 curl_setopt_array($ch,[
  CURLOPT_URL=>$url,
  CURLOPT_RETURNTRANSFER=>true,
  CURLOPT_CUSTOMREQUEST=>"PUT",
  CURLOPT_HTTPHEADER=>[
   "Authorization: {$authorization}",
   "Content-Type: application/json"
  ],
  CURLOPT_POSTFIELDS=>$body
 ]);

 $response=curl_exec($ch);

 $httpCode=curl_getinfo($ch,CURLINFO_HTTP_CODE);

 curl_close($ch);

 job_log($jobId,'info','쿠팡 prepare HTTP: '.$httpCode);
 job_log($jobId,'info','쿠팡 prepare response: '.$response);
 job_log($jobId,'info','쿠팡 상품준비중 호출: '.count($shipmentBoxIds));
}
```

---

# 13. 정상 응답

HTTP

```
200
```

응답

```json
{
 "code":"SUCCESS"
}
```

판매자센터 상태

```
신규주문
↓
상품준비중
```

---

# 14. 로젠 택배 연동

로젠 엑셀 생성 조건

```
tracking_no IS NULL
AND lozen_exported_at IS NULL
```

엑셀 생성 후

```
lozen_exported_at = NOW()
```

로젠 업로드 후

다운로드 받은 송장 엑셀

```
import_lozen_invoice.php
```

처리

```
tracking_no 저장
lozen_uploaded_at 저장
```

---

# 15. 쿠팡 배송중 API (다음 구현)

Endpoint

```
PUT
/v2/providers/openapi/apis/api/v4/vendors/{vendorId}/orders/invoices
```

Request Body

```json
{
 "vendorId":"A00180903",
 "orderItemInvoices":[
  {
   "orderItemId":123456,
   "trackingNumber":"123456789",
   "deliveryCompanyCode":"KGB"
  }
 ]
}
```

필요 데이터

|필드|DB 컬럼|
|---|---|
orderItemId|order_no
trackingNumber|tracking_no
deliveryCompanyCode|carrier_name

---

# 16. 최종 자동화 흐름

```
collect_orders
↓
orders_raw 저장
↓
normalize_coupang
↓
coupang_order_excel 저장
↓
shipmentBoxIds 수집
↓
쿠팡 상품준비중 API
↓
로젠 엑셀 생성
↓
로젠 업로드
↓
송장번호 DB 반영
↓
쿠팡 배송중 API
```

---

# 17. 현재 시스템 개발 상태

|기능|상태|
|---|---|
쿠팡 주문 수집|완료
주문 정규화|완료
상품준비중 API|완료
로젠 엑셀 생성|완료
송장 업로드|완료
쿠팡 배송중 API|미구현

현재 진행률

```
약 90%
```

---

# 18. 다음 개발 작업

다음 구현

```
coupang_ship.php
```

기능

```
송장 존재 주문 조회
↓
orderItemId + trackingNumber + carrierCode 생성
↓
쿠팡 배송중 API 호출
↓
배송중 상태 변경
```

이 작업 완료 시

```
쿠팡 주문 자동화 100%
```

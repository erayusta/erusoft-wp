# Changelog

## 1.0.1 — 2026-10-15

- Yeni `eru_tsc_set_tracking( $order, $carrier, $number )` fonksiyonu (ve `Eru_TSC_Tracking::save_tracking()`): başka eklentiler takip numarasını elle girişle aynı yoldan kaydeder; yeni numarada isteğe bağlı müşteri notu e-postası gider ve `eru_tsc_tracking_saved` eylemi çalışır. Geriye dönük uyumludur.
- Sipariş ekranı arka planda (ör. bir kargo entegrasyonu) takip numarası yazılmadan önce açıldıysa, kaydetmek artık bu numarayı silmez: dokunulmamış alanlar o anki değeri korur.
- New `eru_tsc_set_tracking()` public save path for other plugins (customer note and `eru_tsc_tracking_saved`, as for manual entries). Saving a stale order screen no longer blanks a tracking number written in the background.

## 1.0.0 — 2026-10-12

- İlk genel sürüm.
- Initial public release.

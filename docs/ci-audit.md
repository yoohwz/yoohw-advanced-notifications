# Audit Advanced Notifications theo workflow CI

Ngày thực hiện: 2026-10-28. Mã gốc: `356247d`; kiểm chứng local gồm workflow mới và các sửa đổi bên dưới.

## Phát hiện và xử lý

1. **[P1] Công việc lỗi queue có thể tự tiếp tục sau deactivation/reactivation.** Tái hiện bằng Action Scheduler thật: tạo campaign, ép enqueue thất bại, deactivate, mô phỏng quá thời gian retry rồi mở lại reconciliation. Trước sửa, recipient chuyển từ `queue_failed` sang `sent`, campaign không có cờ hủy. Deactivation nay hủy cả `queue_failed`/`schedule_failed` và lưu cờ hủy cho campaign có recipient đã hủy. Bốn assertion mới kiểm tra fixture lỗi, không tự tiếp tục gửi, không thể retry thủ công và không hiển thị recovery sau khi bật lại. Không thay đổi kết quả recipient `sent` hoặc `unknown`.
2. **[P2] Integration test không chạy được trên dependency tối thiểu.** Action Scheduler của WooCommerce 9.3.3 chưa truyền tham số `$unique` cho hai pre-scheduling filter. Callback kiểm thử yêu cầu tham số này nên dừng với `Too few arguments`. Bổ sung giá trị mặc định cho callback; chạy lại trên dependency cũ và mới.
3. **Plugin Check báo nhầm hai lỗi prepared SQL.** `write_durable_option()` chọn một trong hai câu SQL cố định và dùng placeholder cho toàn bộ identifier/value. Đã đối chiếu câu lệnh và thêm giải thích cùng annotation tại đúng câu query; không tắt kiểm tra SQL trên toàn plugin. Còn 41 warning về direct query/no caching; các đọc trạng thái vận hành cố ý bỏ qua options cache để tránh dữ liệu cũ khi nhiều worker chạy đồng thời.
4. **Cố định bộ nhớ runner ở 512 MiB.** Lần giải nén WordPress 7.1 bằng PHP local có giới hạn 128 MiB bị hết bộ nhớ; regression workflow nay khai báo giới hạn 512 MiB, thống nhất với runtime audit.

## Kiểm chứng

Các bộ kiểm tra được chạy bằng lệnh của workflow mới. Database thử nghiệm có tên riêng chứa `reliability`; cron bị tắt và mail bị chặn bằng MU plugin. Các profile chạy đồng thời dùng bản sao checkout và thư mục language riêng.

| WordPress | PHP | WooCommerce | Integration | Internationalization | Reliability |
| --- | --- | --- | --- | --- | --- |
| 6.6 | 8.1.29 | 9.3.3 | 66/66 | 12/12 | 54/54 |
| 6.6 | 8.2.35 | 9.3.3 | 66/66 | 12/12 | 54/54 |
| 7.1 | 8.1.29 | 11.2.1 | 66/66 | 12/12 | 54/54 |
| 7.1 | 8.4.21 | 11.2.1 | 66/66 | 12/12 | 54/54 |
| nightly (7.2-beta2-64861) | 8.4.21 | 11.2.1 | 66/66 | 12/12 | 54/54 |

Tổng cộng **660 assertion đạt** trên năm profile. CI contracts, actionlint, shell syntax, PHP syntax tối thiểu 8.1 và JavaScript syntax đã đạt. Plugin Check 2.1.0: **0 error, 41 warning** trên cả WordPress 6.6 và 7.1, với payload được dựng bằng `.distignore`.

Profile 6.6/PHP 8.1 giữ `runner.log` của lần phát hiện lỗi callback đầu tiên. Sau sửa, ba bộ test được chạy lại trên chính site riêng đó; kết quả cuối nằm trong `integration.log`, `internationalization.log` và `reliability-full-fixed/results.json`. Bốn profile còn lại hoàn tất trực tiếp bằng `bin/run-wp-regression.sh`.

## Bằng chứng và giới hạn

- Thư mục bằng chứng: `/tmp/advanced-notifications-audit-20261028/`. Mỗi profile lưu log runner, môi trường, kết quả từng assertion và transport giả lập.
- Tái hiện trước sửa: `deactivation-before.json` và `deactivation-before/transport.jsonl`.
- Tổng hợp: `summary.json`; hash mã runtime, bộ test và runner đã đối chiếu giữa các checkout: `audited-source-sha256.json`.
- Kết quả Plugin Check: `plugin-check/results-fixed.json` và `plugin-check/results-wp71.json`.
- Audit local dùng WP-CLI 2.12.0 và MySQL 8.0.35 qua socket của Local thay cho MySQL service trong GitHub Actions; giữ nguyên lệnh kiểm thử. Bảng trên ghi kết quả local; kết quả GitHub hosted runner được cập nhật trong pull request.
- Đã rà các đường admin capability/nonce, unsubscribe token, payload integrity, claim, retry và deactivation. Kết quả mail được kiểm tra tại biên WordPress, không xác nhận SMTP/inbox thật.
- Không merge, release, deploy hoặc thay branch protection.

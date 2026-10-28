# Milestone: Chiến dịch gửi đáng tin cậy và có thể phục hồi

HEAD đối chiếu: `8843041a77a6456003aab435091c804b6a157df7` (0.2.2). Không có hướng dẫn repository bổ sung ngoài `readme.txt`; áp dụng AGENTS.md do Human cung cấp.

## Vấn đề đã xác nhận

- `claim_recipient_delivery()` dùng `add_option()`. WordPress kiểm tra cache rồi thực hiện `INSERT ... ON DUPLICATE KEY UPDATE`; hai tiến trình vượt qua bước kiểm tra có thể cùng nhận kết quả thành công. Khóa xác nhận và dispatcher dùng đọc rồi ghi transient cũng có khoảng đua. Khách hàng có thể nhận trùng trong cùng lượt gửi.
- `track_recipient_status()` đã bảo vệ `sent` khỏi cập nhật ngược. Tuy nhiên `dispatch_campaign_batch()` gọi `update_tracking_row_state()` ghi vô điều kiện, bỏ qua bảo vệ này; worker chạy nhanh có thể gửi xong trước cập nhật `queued/scheduled`.
- `add_unsubscribed_email()` đọc rồi ghi toàn bộ option chung. Hai unsubscribe có thể ghi đè nhau; handler không kiểm tra việc lưu trước khi báo thành công.
- Reconciliation chỉ tổng hợp trạng thái; không khôi phục dispatcher bị mất, chuẩn bị dở hoặc recipient không còn job. Retry cũ chỉ nhắm `schedule_failed` và có đường dựng lại đối tượng từ audience hiện tại. Claim được xóa khi `wp_mail()` trả về false, tạo khả năng gửi lại khi thực tế chưa rõ transport đã nhận chưa.
- `save_campaign_history()` cắt 100 phần tử của option cũng được dispatcher dùng làm dữ liệu vận hành. Campaign cũ có thể mất khả năng theo dõi và tiếp tục dù recipient/payload/job vẫn còn.

Nguồn tham khảo nền tảng: [mã nguồn add_option](https://developer.wordpress.org/reference/functions/add_option/) và [ý nghĩa kết quả wp_mail](https://developer.wordpress.org/reference/functions/wp_mail/). Các kết luận lỗi dựa thêm trên mã WordPress và plugin tại môi trường kiểm chứng.

## Hành vi mới

- Claim bằng một câu `UPDATE ... WHERE status IN (...) AND claim_token = ''` trên bảng recipient; chỉ worker cập nhật đúng một dòng được gửi. Token cùng trạng thái giữ lại sau handoff. Job trùng không thay đổi kết quả của recipient. Campaign mới có identity mới vẫn gửi được cho cùng địa chỉ.
- Xác nhận giữ manifest bất biến gồm nội dung và danh sách recipient. Khóa MySQL theo campaign chỉ tồn tại trong vòng đời connection, không tự hết hạn khi worker còn chạy. Chuẩn bị dở được tiếp tục bằng các insert idempotent; chỉ dispatch khi chuẩn bị hoàn tất. Không lấy audience hiện tại để điền chỗ trống của campaign cũ.
- Mỗi campaign và opt-out có option riêng, `autoload=no`. Các đọc vận hành lấy trực tiếp database. Cập nhật muộn từ dispatcher không được ghi đè processing, handoff hoặc kết quả cuối.
- Reconciliation hiện có chạy khi mở Campaigns & Logs và qua cron hàng giờ. Nó tạo lại dispatcher thiếu và requeue recipient chưa handoff khi job mất/thất bại. Queue/schedule submission được retry tối đa 3 lần, cách ít nhất 5 phút; có thể dùng nút phục hồi sớm hơn trong giới hạn đó. Giữ nguyên UTC due time; mục đã tới hạn có thể được enqueue ngay.
- Lỗi xác định trước transport được ghi `failed` cùng lý do và cần người vận hành sửa dữ liệu/cấu hình rồi phục hồi. Không mặc định retry an toàn chỉ từ `wp_mail(false)`, exception hoặc worker chết. Những trường hợp này là `unknown`, không được retry. Processing quá 15 phút được chuyển thành unknown; nếu worker ban đầu trả về xác nhận sau đó, chỉ token ban đầu được ghi handoff thành công.
- Job chờ quá 30 phút sau due time có thông báo kiểm tra cron/Scheduled Actions. Pending job đang tồn tại được giữ lại. Người vận hành thấy lý do từng recipient và bước tiếp theo; các campaign đang hoạt động/cần xử lý được đưa lên trước lịch sử đã hoàn tất.
- `sent` được giữ làm token tương thích, nhưng nhãn là **Handed to mail system**. UI giải thích đây không phải xác nhận đến inbox. Counts bao gồm processing, queue failure, cancelled và outcome unknown.

## Dữ liệu và nâng cấp

- `dbDelta()` chỉ thêm `claim_token`, `attempts`, `error_kind` vào bảng recipient; schema 3 chỉ được đánh dấu sau khi các cột tồn tại. Các dòng/job cũ giữ nguyên identity và trạng thái.
- Option suppression cũ giữ nguyên và tiếp tục được hợp với opt-out mới khi đọc. Hỗ trợ cả dạng key email, list email và record có trường email. Không có bước xóa/chuyển hàng loạt có thể làm mất opt-out khi bị ngắt. Chỉ báo unsubscribe thành công sau khi đọc lại bản ghi đã lưu.
- Option lịch sử cũ được giữ và làm nguồn dự phòng; record riêng ghi đè đúng campaign tương ứng. Không còn cắt dữ liệu vận hành ở 100 campaign. UI tiếp tục phân trang 20 campaign.
- Shared payload giúp tìm lại campaign đã bị cắt khỏi lịch sử; pending/in-progress inline jobs cũ được nhập theo từng trang với `INSERT IGNORE`. Import có thể chạy lại nếu bị ngắt; marker chỉ ghi sau khi hoàn tất. Không mở rộng audience khi thiếu dữ liệu; UI giữ cảnh báo audience cũ không đầy đủ.
- Claim option cũ vẫn được đọc. `sent` chặn gửi lại; `sending` chuyển thành unknown. Deactivation giữ dữ liệu/opt-out và không tự tạo lại công việc đã hủy.
- Khi Human triển khai bản nâng cấp, cần dừng worker đang nạp mã 0.2.2 trước khi thay mã; không thể sửa đoạn mã cũ đã nằm trong bộ nhớ một tiến trình đang chạy. Không downgrade trực tiếp sang 0.2.2: bản cũ không đọc các opt-out/claim mới. Merge, release, deployment và rollback thuộc Human.

## Kiểm chứng và bằng chứng

Bộ `tests/reliability.py` dùng nhiều tiến trình PHP độc lập, database thật và claim/job lifecycle thật của Action Scheduler. Barrier đặt trước cập nhật tranh chấp; `SIGKILL` ngắt tiến trình ở giữa preparation, sau lưu job và quanh transport. Chỉ biên `pre_wp_mail` được chặn và ghi nhận vào `transport.jsonl`; không có thư thử đi ra ngoài.

Chạy trong WordPress riêng, chỉ bật WooCommerce và checkout này. Tên database phải chứa `reliability`; bộ kiểm thử xóa fixture plugin/hook trong database này trước khi chạy. Không chạy trên database cửa hàng.

```sh
python3 tests/reliability.py --wp-command /path/to/disposable-wp-wrapper --output /tmp/an-evidence-unique
wp eval-file wp-content/plugins/yoohw-advanced-notifications/tests/integration.php
wp --skip-plugins --skip-themes eval-file wp-content/plugins/yoohw-advanced-notifications/tests/internationalization.php
```

`results.json` lưu commit SHA, tình trạng working tree, từng assertion, recipient state, attempts/action IDs, opt-out và job counts. `workers.log` lưu đầu ra từng tiến trình; `transport.jsonl` lưu số lần handoff giả lập. Kết quả cuối và commit chính xác được gắn trong PR/bàn giao sau khi chạy lại trên cây mã đã commit.

## Giới hạn

- Đã kiểm chứng transport ở biên WordPress, không chứng minh giao thư SMTP/inbox thật. Generic `wp_mail` không cung cấp hợp đồng idempotency hoặc truy vấn kết quả transport, vì vậy mặc định an toàn với kết quả mơ hồ là unknown.
- Phục hồi tự động phụ thuộc cron hoặc lượt mở trang quản trị. Cron ngừng hoàn toàn cần người vận hành sửa; UI hiển thị job overdue khi được mở.
- Không khôi phục được audience lịch sử đã bị xóa trước nâng cấp nếu không còn snapshot/recipient/job. Không tự suy đoán người nhận.
- Giữ dữ liệu campaign thay vì tự xóa theo số lượng; chưa thêm chính sách retention mới. Chỉ kiểm chứng stack local đã ghi trong PR, chưa chạy ma trận mọi phiên bản WordPress/MySQL hoặc external persistent object cache.

## Tự review PR #1

Review sau commit `fa240d1`, giữ nguyên phạm vi chống trùng/phục hồi/opt-out. Đã tái hiện và sửa bốn lỗi:

- **[P1] Preparation lỗi có thể gửi tiếp sau deactivation.** Campaign có manifest dở nhưng trạng thái `needs_attention` không được ghi cờ hủy; reconciliation sau đó thêm recipient còn thiếu và đã handoff một thư trong ca tái hiện. Deactivation nay lưu cờ hủy cho mọi manifest chưa chuẩn bị xong và không hiển thị retry cho campaign đã hủy.
- **[P2] Gián đoạn dispatcher vượt giới hạn retry.** `dispatch_pending` không được lọc/giữ chỗ theo `attempts < 3`; sau ba lần SIGKILL trước lưu job, lần tiếp theo tăng `attempts` lên 4. Dispatcher nay kiểm tra giới hạn ở cả chọn recipient và cập nhật nguyên tử, đồng thời dừng tạo dispatcher tiếp theo khi không còn recipient hợp lệ. Job đã lưu ở lần thứ ba vẫn được chạy và chỉ handoff một lần.
- **[P2] Opt-out sau lỗi trước transport không chuyển sang skipped.** Claim token của `failed` được giữ lại nhưng hàm cập nhật dành cho scheduler từ chối trạng thái/token này, khiến UI tiếp tục hiển thị lỗi và cho retry. Recovery nay ghi `skipped_unsubscribed` bằng so khớp cả trạng thái và token đã đọc; không ghi đè worker đang xử lý. Opt-out vẫn được bảo toàn trước và sau sửa; ca lỗi này không gửi thêm thư.
- **[P2] Preparation phục hồi có thể dời phần còn thiếu sang ngày hôm sau.** Recipient đã lưu giữ due time ban đầu, còn recipient chưa lưu được tính rollover theo ngày phục hồi. Hàm tính lịch nay nhận thời điểm xác nhận trong manifest; recipient cũ giữ nguyên dữ liệu và phần còn thiếu giữ đúng lịch đã xác nhận. Ca kiểm chứng mô phỏng thời gian trôi bằng timestamp trong manifest, sau đó chạy job thật và xác nhận hai recipient đều handoff đúng một lần.

Mười assertion mới bao phủ các ca trên, gồm SIGKILL trước/sau lưu job, giới hạn retry tự động/thủ công, job đã lưu ở lần cuối, opt-out sau lỗi cấu hình và deactivation của preparation lỗi. Chạy riêng bằng `--review-only`, hoặc chạy cùng toàn bộ 40 kiểm tra ban đầu. Không thay schema, không di chuyển suppression, không thêm cơ chế gửi hoặc phục hồi mới. Commit cuối và kết quả chạy lại được ghi trong PR/bàn giao.

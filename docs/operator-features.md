# Tính năng vận hành campaign (chưa phát hành)

Nền đối chiếu: `74353181835147d8e68d9bca87a3d801c3e772dd` trên local và `origin/main`. Giữ schema recipient 3, Action Scheduler, manifest xác nhận và durable opt-out hiện có. Không thêm service hoặc kênh gửi khác.

## Cách sử dụng

- **Campaign:** chọn nguồn, nội dung, điều kiện và lịch. Dùng **Save draft** để lưu cả khi chưa đủ nội dung/người nhận. Mở lại trong **Drafts and saved audiences**; draft không hết hạn và không tạo delivery job. Mỗi lần lưu có revision riêng; preview của revision trước không xác nhận được. Gửi thử lưu cùng draft, không làm mất lựa chọn lịch. Ba template chỉ chèn nội dung vào editor để tiếp tục sửa.
- **Preview Recipients:** xem tổng chọn/loại theo từng bước, kiểm tra một địa chỉ, chọn một trong tối đa 20 người mẫu, chuyển Desktop/Mobile. Preview cá nhân hóa dùng cùng phép thay biến và bộ dựng HTML WooCommerce với email thực. Khi audience thay đổi trước xác nhận, plugin đưa về preview mới và yêu cầu xác nhận lại. Tập đã xác nhận không lấy thêm khách hàng sau đó.
- **Campaigns & Logs → View log:** dùng Pause, Resume, Cancel; nhân bản bằng **Duplicate as new draft**. Bản sao chỉ có dữ liệu soạn thảo/tiêu chí/lịch, không có campaign/delivery identity, job, claim hoặc kết quả. Xem tiến độ, thời điểm tiến triển gần nhất, trạng thái queue, lý do và tìm/lọc tối đa 200 recipient mỗi lần. Dùng **Inspect Scheduled Actions** khi có cảnh báo overdue. Retry vẫn có giới hạn ba lần và giữ nguyên cơ chế hiện có.
- **Save audience criteria:** lưu tên và toàn bộ nguồn/bộ lọc/loại trừ. Mở từ thư viện để sửa hoặc dùng lại; phải tạo preview mới. Khi mở một saved audience, nút lưu cập nhật tiêu chí đó; việc này không sửa manifest campaign đã xác nhận. Có thể xóa saved audience hoặc draft trong thư viện.
- **Settings:** đặt hạn mức phút/ngày, retention; tìm trạng thái đăng ký/suppression; nhập một email mỗi dòng. Chọn trạng thái và nguồn bằng chứng, **Preview import**, đọc số hợp lệ/trùng/từ chối và lý do rồi **Apply previewed import**. Kết quả hiển thị số áp dụng/không áp dụng. Một email cũng có thể được quản lý bằng chính luồng này.
- **Tools → Export/Erase Personal Data:** dùng email của tài khoản hoặc guest. Export trả dữ liệu recipient, evidence, suppression và tham chiếu danh sách/log cũ của đúng địa chỉ, không xuất danh sách của người khác.

## Điều khiển, tốc độ và phục hồi

Pause/Cancel và admission dùng cùng khóa theo campaign tại ranh giới nhận quyền gửi; dispatcher/preparation vẫn dùng khóa campaign hiện có. Người đã nhận claim trước thao tác được hoàn tất và ghi kết quả bằng token cũ. Pause không thay đổi due time hoặc attempts. Resume tiếp tục cùng campaign, không mở rộng audience. Cancel có cờ bền vững, không thể Resume; reconciliation, retry, dispatcher và worker đều kiểm tra. Campaign đã hủy vẫn được tổng hợp kết quả worker đang chạy. Nếu tiến trình ngắt sau khi lưu cờ hủy nhưng trước khi cập nhật recipient, reconciliation hoàn tất bước hủy idempotent; worker vẫn bị chặn ngay bởi cờ đã lưu. Deactivation hủy cả các trạng thái chờ mới.

Không thu hồi được email đã handoff. `sent` tiếp tục mang nghĩa handed to mail system; `unknown` không tự retry. Việc sửa draft/template/saved audience chỉ ảnh hưởng phiên bản soạn thảo.

Hạn mức mặc định **0 = không giới hạn**. Dùng cửa sổ cố định theo phút UTC và ngày UTC, không phải cửa sổ trượt. Một mutex database chung bảo vệ bộ đếm toàn website, xuyên campaign/worker. Chỉ unique claim thành công mới giữ một slot. Slot được giữ thận trọng khi process chết hoặc lỗi cấu hình trước transport; đây là trần **bắt đầu gửi**, có thể thấp hơn số handoff. Gửi thử không nằm trong hạn mức campaign. Các hook WooCommerce khác không bị ảnh hưởng.

Chờ admission, pause hoặc hết hạn mức không tăng attempts, không chuyển thành failed và không thay due time gốc. UI hiển thị lý do/thời điểm kiểm tra tiếp theo theo hạn mức; nếu mutex đang bận, công việc được thử admission lại sau một phút. Slot tự hết hiệu lực ở biên cửa sổ, kể cả worker chết. Job bị mất vẫn được reconciliation dựng lại từ recipient đã lưu. Cron ngừng cần sửa cron; UI không thể tự bảo đảm queue chạy khi không có worker.

## Ý nghĩa audience và biến nội dung

- Nguồn order giữ các nguồn hiện có, bao gồm guest. Mặc định order processing/completed; khi chọn trạng thái khác, chỉ các trạng thái được chọn được dùng.
- Ngày dùng **order creation time**, không phải ngày thanh toán/hoàn thành. Khoảng ngày bao gồm cả ngày cuối theo timezone cửa hàng. N ngày gần nhất là N × 24 giờ đến hiện tại; không dùng đồng thời với khoảng ngày.
- Product/variation và các điều kiện ngày/trạng thái/billing country phải khớp **trên cùng một đơn**. Category bao gồm category con và dùng taxonomy hiện tại của sản phẩm cha; nếu chọn cả product và category, cùng đơn phải thỏa cả hai.
- Country của nguồn order lấy từ chính đơn khớp; registered users lấy profile `billing_country`; manual list không có country. Điều kiện order chỉ áp dụng nguồn order; country không áp dụng manual. UI ẩn/vô hiệu hóa điều kiện không phù hợp và server từ chối đầu vào sai/không áp dụng được. Không âm thầm bỏ điều kiện để mở rộng audience. Khi mở lại draft/audience, các category/status/country/role không còn tồn tại vẫn giữ lựa chọn với nhãn unavailable; người soạn phải sửa hoặc bỏ rõ ràng, không bị tự chuyển thành điều kiện trống.
- History `handoff` cần record `sent`. **Known unsent terminal outcome** chỉ nhận các record kết thúc xác định chưa handoff; thiếu record, đang chờ, processing, duplicate không rõ và unknown đều không đủ điều kiện. Hash kết quả giữ sau retention vẫn dùng được cho điều kiện này.
- Người có email chưa có evidence mặc định là **unknown**. Account/order/manual list không tạo opt-in. Khi import subscribed, quản trị viên phải nhập nguồn bằng chứng; thời gian ghi nhận là thời gian import, không suy đoán thời gian consent ban đầu. Opt-out luôn ưu tiên, kể cả import subscribed sau đó hoặc nguồn khác.
- Preview có tổng ban đầu của nguồn với trạng thái order đã chọn, số bị loại bởi điều kiện order, trạng thái đăng ký/history, loại trừ/suppression. Address inspector giải thích bước loại hoặc lý do được chọn theo dữ liệu hiện tại. Tập người mẫu có giới hạn 20; recipient diagnostics hiển thị tối đa 200 kết quả và cho tìm theo email/lý do/trạng thái.
- `{customer_name}` trong **body** dùng tên recipient đã chốt, thiếu tên thì dùng email. `{product_name}` là nhãn chung của các sản phẩm campaign; nếu không chọn sản phẩm thì là nhãn nguồn audience. Đây không phải sản phẩm riêng từng khách mua. Subject và heading là chuỗi literal. Các biến không được hỗ trợ giữ nguyên để người soạn nhận ra và sửa.

## Lịch gửi

Campaign mới có ba lựa chọn: ngay sau xác nhận; một thời điểm theo timezone cửa hàng được ghi trong payload; hoặc cùng wall-clock local theo từng recipient. Với local, chỉ country có đúng một IANA timezone được dùng làm **ước lượng** từ billing location. Country có nhiều timezone, country không hỗ trợ hoặc thiếu dữ liệu dùng UTC; không suy đoán chắc chắn từ state/city. Manual list dùng UTC. Preview cho biết fallback count và khoảng UTC sớm nhất/muộn nhất.

Timezone cửa hàng được lưu cùng preview, kể cả dạng offset như `+07:00`. Lịch mới từ chối giờ đã qua, giờ không tồn tại và giờ lặp do DST. Không tự dời sang ngày khác. Nếu giờ đã qua trong lúc chờ xác nhận, người dùng phải chọn lại. Sau xác nhận, due UTC của từng người được lưu trong manifest và recipient; resume/retry/recovery giữ nguyên dù thời gian đã qua hoặc cấu hình timezone cửa hàng đổi. UI phân biệt due gốc với chờ thêm do pause, queue hoặc hạn mức.

Campaign cũ không có `schedule_policy` giữ thuật toán legacy, bao gồm due đã lưu và reference time của manifest; không tính lại lịch của recipient đã lưu khi nâng cấp. Bản nhân đôi cần preview/xác nhận lại dưới chính sách mới.

## Privacy, retention và nâng cấp

Không đổi schema hoặc di chuyển/xóa opt-out cũ khi nâng cấp. Các option draft/audience/control/evidence/counter dùng namespace `yoohw_an_`, không autoload. Cờ hủy, claim, retry bound và old inline job compatibility được giữ. Dừng worker đang nạp mã cũ trước khi thay mã, vì mã đã ở trong bộ nhớ không thể được thay bằng hook mới. Human quyết định merge/release/deploy/rollback; không downgrade trực tiếp về bản không hiểu cờ control/erasure mới.

Retention mặc định **0 = tắt**. Với N ngày, reconciliation hàng giờ dọn sau khi campaign kết thúc được ghi nhận đủ N ngày. Không dọn pending/processing/paused/rate-wait, preparation chưa xong hoặc lỗi còn có thể retry. Muốn đóng một campaign cần xử lý để retention có hiệu lực thì Cancel phần còn lại. Record tombstone được lưu trước khi bỏ manifest/shared content/fallback history; marker hoàn tất chỉ lưu sau các bước dọn, nên lần chạy sau tiếp tục được nếu bị ngắt.

Dọn bỏ nội dung, địa chỉ, tên, billing location, lý do cá nhân, snapshots/preview và arguments trong Action Scheduler của plugin. Giữ campaign/delivery IDs, email hash, claim token, outcome, counters tối thiểu và suppression. Không xóa jobs/logs của hook WooCommerce khác. Draft/saved audience có vòng đời riêng, chỉ xóa rõ ràng hoặc qua erasure. Fallback WooCommerce logs riêng của plugin được xóa cùng source khi privacy/retention chạy; trạng thái vận hành chính vẫn nằm trong recipient table. Host/server logs và provider logs ở ngoài cơ chế dữ liệu plugin.

Erasure ghi hash bảo vệ trước, ngăn admission và việc dựng recipient từ snapshot/job cũ. Sau đó xóa identity khỏi table, manifest, record, old aggregate history/logs, draft, saved audience, import/preview transient và Action Scheduler arguments. Hash opt-out/erasure và chống gửi trùng vẫn giữ, không khôi phục lại email từ bản dự phòng. Erasure cũng ngăn gửi lại địa chỉ đó trong tương lai qua các nguồn hiện có; không có luồng tự khôi phục consent từ lịch sử.

Nếu worker đã nhận claim đang gửi, callback thông báo giữ dữ liệu và cần chạy erasure lại sau khi worker hoàn tất. Nội dung dùng chung của campaign còn hoạt động cũng được giữ nguyên để không làm hỏng checksum/nội dung đã xác nhận; nếu có văn bản cá nhân trong body/subject thì chạy lại erasure sau khi campaign kết thúc. Snapshot người nhận vẫn được xử lý. Lỗi storage trả kết quả chưa xóa hoàn tất; marker bảo vệ được giữ nếu đã ghi thành công. Bản backup bên ngoài plugin cần tuân theo chính sách backup của website.

## Kiểm chứng

CI runner chạy integration, internationalization, `operator-features.php`, toàn bộ reliability harness và `operator-concurrency.py` với database và Action Scheduler thật; transport được chặn. Bộ concurrency mới dùng worker độc lập, giữ transport tại barrier để thao tác pause/cancel/erasure khi có claim đang chạy, và cho hai campaign tranh cùng quota. Không chạy bộ fixture này trên database cửa hàng.

Kết quả theo revision cuối được ghi trong PR và bàn giao. Kiểm tra giao diện local bằng Chrome trên site riêng, gồm draft chưa hoàn chỉnh, TinyMCE/template, preview cá nhân hóa/mobile, schedule/store-offset, xác nhận, pause/resume/cancel, duplicate, saved audience và preview/apply suppression import. Bằng chứng thử nghiệm local nằm ngoài payload phân phối.

### Review correction: known unsent history

The known-unsent history filter excludes recoverable failures until the existing three-attempt limit is exhausted. A terminal configuration failure requires explicit modern configuration provenance; legacy failures without that evidence remain excluded. Pending/in-progress delivery jobs (including unlinked duplicates), legacy claims and handoff evidence exclude the address. A durable campaign cancellation can establish a cancelled recipient as unsent because admission remains blocked even when a stale scheduled action exists.

### UI đã duyệt trong PR #3

Workspace có năm mục: Campaigns, Compose, Library, Subscribers và Settings. Compose chia Audience → Content → Schedule → Review; thanh tên/Save draft luôn nằm trên phần soạn. Review dẫn tới preview người nhận thật trước khi xác nhận. Khi không có JavaScript, các phần vẫn hiện trong cùng form. Library chứa draft và saved audience; Subscribers chứa tìm kiếm cùng preview/apply import; Settings chứa sender, hạn mức và retention.

Màu nhấn, hover, focus, lựa chọn và phần mở rộng kế thừa Admin Color Scheme của người dùng WordPress; không thêm setting màu riêng. Email preview vẫn dùng màu thương hiệu từ WooCommerce như email thực, độc lập với màu giao diện quản trị. Chuyển nguồn không tự xóa điều kiện đã nhập: điều kiện không phù hợp được giữ và preview bị từ chối cho tới khi người dùng sửa hoặc xóa rõ ràng.

### Bản sửa sau review độc lập

Các ghi dữ liệu cá nhân được tuần tự hóa với erasure và kiểm tra lại protective hashes ngay tại điểm lưu, kể cả nguồn bằng chứng và diagnostic text tham chiếu địa chỉ khác. Worker đã handoff vẫn ghi outcome ngay; khi không thể kiểm tra diagnostic an toàn, chỉ bỏ diagnostic text. Public opt-out chờ khóa tối đa hai giây để các yêu cầu đồng thời ngắn đều lưu được; timeout/storage failure vẫn báo lỗi.

Erasure giữa preparation giữ hash, dấu erased và due time tối thiểu trong manifest; cancelled placeholder bảo toàn số người đã xác nhận mà không ghi đè claim/outcome đã có. Legacy AS action chưa có row, bị privacy-busy trước handoff, lưu checkpoint chỉ gồm action ID và hashes. Importer hiện có chỉ phục hồi đúng failure có checkpoint khớp; không replay failed/unknown chung. Xóa checkpoint sau khi row/snapshot/association đã lưu; args đã thay đổi hoặc địa chỉ đã erase không được dựng lại.

Các form dùng label phía trên, chiều cao ô nhập/nút 40px và nhóm trường responsive; editor, checkbox và danh sách chọn nhiều giữ kích thước phù hợp. Deactivation lưu cancellation gate cho cả job chỉ còn trong scheduler và manifest đã xác nhận nhưng preparation chưa tạo job, trước khi unschedule. Lỗi đọc/ghi authority báo deactivation chưa hoàn tất; gate đã lưu có thể đã hủy một phần công việc, cần sửa storage rồi thử lại. Preview/test chưa xác nhận được giữ nguyên.

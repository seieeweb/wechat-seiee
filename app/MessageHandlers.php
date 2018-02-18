<?php
/**
 * Created by PhpStorm.
 * User: hebingchang
 * Date: 2018/2/18
 * Time: 下午7:43
 */

namespace App;

use Carbon\Carbon;
use Log;
use EasyWeChat\Kernel\Messages\News;
use EasyWeChat\Kernel\Messages\NewsItem;
use Illuminate\Support\Facades\Storage;
use Ixudra\Curl\Facades\Curl;

class MessageHandlers
{

    /**
     * 校园卡信息
     * @param $content
     * @param $from
     * @return News
     */
    public function cardHandler($content, $from)
    {
        $jaccount_object = new JaccountApis($from);

        $card = $jaccount_object->getCardDetail();
        $user = $jaccount_object->user_detail;

        $today_detail = $jaccount_object->getCardTransaction();


        $items = [
            new NewsItem([
                'title'       => '校园卡信息',
                'description' => "{$user->name} (校园卡号 {$card->cardNo})\n\n余额: {$card->cardBalance} 元\n过渡余额: {$card->transBalance} 元\n\n
                                    今日消费 {$today_detail->sum} 元, 击败了 {$percent}% 的用户！",
                'url'         => '',
                'image'       => '',
            ]),
        ];

        $news = new News($items);

        return $news;
    }

    /**
     * 课表
     * @param $content
     * @param $from
     * @return News
     */
    public function classTableHandler($content, $from)
    {
        $jaccount_object = new JaccountApis($from);

        $now = Carbon::now();
        $week = $now->diffInWeeks(Carbon::parse(env('SEMESTER_START')));
        $day = Carbon::now()->dayOfWeek;

        $tomorrow = max($day - 6, 0) * (-7) + $day + 1;
        $classes = $jaccount_object->getClasses($week, [$day, $tomorrow]);

        $weekday_str = ['一', '二', '三', '四', '五', '六', '日'];

        $today_class = "今日 (第{$week}周 周{$weekday_str[$day]}) 课程:";
        foreach ($classes[$day] as $class) {
            $teacher = $class['teachers'][0]->name;
            $classroom = $class['classroom'];
            $class_time = join(', ', $class['class']);
            $today_class .= "\n{$class['name']} @ {$classroom} ($teacher)\n第 {$class_time} 节";
        }

        $tomorrow_class = "明日 (第{$week}周 周{$weekday_str[$tomorrow]}) 课程:";
        foreach ($classes[$tomorrow] as $class) {
            $teacher = $class['teachers'][0]->name;
            $classroom = $class['classroom'];
            $class_time = join(', ', $class['class']);
            $tomorrow_class .= "\n{$class['name']} @ {$classroom} ($teacher)\n第 {$class_time} 节";
        }

        $items = [
            new NewsItem([
                'title'       => '我的课程',
                'description' => "$today_class\n\n$tomorrow_class",
                'url'         => '',
                'image'       => '',
            ]),
        ];

        $news = new News($items);

        return $news;
    }

    /**
     * 不定积分
     * @param $content
     * @param $from
     * @return News
     */
    public function integrateHandler($content, $from)
    {
        $exp = str_replace("积分", "", str_replace(" ", "", $content));
        $contents = file_get_contents('http://127.0.0.1:5000/integrate?equation=' . urlencode($exp));
        $filename = md5(str_random()) . '.jpg';
        Storage::disk('public')->put($filename, $contents);

        $items = [
            new NewsItem([
                'title'       => '计算结果',
                'description' => '',
                'url'         => url('storage/' . $filename),
                'image'       => url('storage/' . $filename),
            ]),
        ];

        $news = new News($items);

        return $news;
    }

    /**
     * 求导
     * @param $content
     * @param $from
     * @return News
     */
    public function diffHandler($content, $from)
    {
        $lst = explode(' ', $content);
        $exp = $lst[1];
        if (count($lst) == 2) {
            $times = 1;
        } else {
            $times = (int)str_replace('阶', '', $lst[2]);
        }
        $contents = file_get_contents('http://127.0.0.1:5000/diff?equation=' . urlencode($exp) . '&times=' . $times);
        $filename = md5(str_random()) . '.jpg';
        Storage::disk('public')->put($filename, $contents);

        $items = [
            new NewsItem([
                'title'       => '计算结果',
                'description' => '',
                'url'         => url('storage/' . $filename),
                'image'       => url('storage/' . $filename),
            ]),
        ];

        $news = new News($items);

        return $news;
    }

    /**
     * 泰勒展开
     * @param $content
     * @param $from
     * @return News
     */
    public function taylorHandler($content, $from)
    {
        $exp = str_replace("泰勒展开", "", str_replace(" ", "", $content));
        $contents = file_get_contents('http://127.0.0.1:5000/taylor?equation=' . urlencode($exp));
        $filename = md5(str_random()) . '.jpg';
        Storage::disk('public')->put($filename, $contents);

        $items = [
            new NewsItem([
                'title'       => '计算结果',
                'description' => '',
                'url'         => url('storage/' . $filename),
                'image'       => url('storage/' . $filename),
            ]),
        ];

        $news = new News($items);

        return $news;
    }

    /**
     * 综合测评
     * @param $content
     * @param $from
     * @return News
     */
    public function zhcpHandler($content, $from)
    {
        $student_id = Jaccount::where('wechat_id', $from)->first()->student_id;

        $response = Curl::to('https://z.seiee.com/api/wechat/getScore')
            ->withData(array(
                'student_id' => $student_id,
                'sign' => md5(md5($student_id) . '!SEIEE$' . Carbon::now()->toDateString())
            ))
            ->returnResponseObject()
            ->post();

        $data = json_decode($response->content);

        $detail = [];
        foreach ($data->data->all_items as $item) {
            $detail[] = "{$item->item_code} {$item->quality->category}: {$item->comment}\n{$item->quality->credit} × {$item->score}";
        }

        $items = [
            new NewsItem([
                'title'       => '综合测评',
                'description' => $data->semester->year . '-' . ($data->semester->year + 1) . '学年 第' . $data->semester->semester .
                    "学期\n" . $data->data->student->name . ': ' . $data->data->score . '分 (' . $data->data->rank . "名)\n\n" . join("\n\n", $detail),
                'url'         => "https://z.seiee.com/scores/search?student_no={$student_id}",
            ]),
        ];

        $news = new News($items);

        return $news;
    }
}
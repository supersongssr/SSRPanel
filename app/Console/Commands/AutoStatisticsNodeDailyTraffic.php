<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Models\SsNode;
use App\Http\Models\SsNodeTrafficDaily;
use App\Http\Models\SsNodeTrafficHourly;
use App\Http\Models\UserTrafficLog;
use Log;

class AutoStatisticsNodeDailyTraffic extends Command
{
    protected $signature = 'autoStatisticsNodeDailyTraffic';
    protected $description = '自动统计节点每日流量';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $jobStartTime = microtime(true);
        $nodeList = SsNode::query()->where('status', 1)->where('id','>',9)->orderBy('id', 'desc')->get();  //只获取在线的节点
        // 1 2 3 节点是 用来发广告的节点，嘎嘎 可以有，哈哈 嘿嘿 嘎嘎 喜喜
        foreach ($nodeList as $node) {
            //$this->statisticsByNode($node->id);
            # 按照之前的算法来计算。不错的选择。
            #获取节点
            #计算 差值
            #记录每日流量
            #写入新的记录值
            #如果为负，就写入0 这条取消，为啥要<0 好像没必要
            $traffic_today = $node->traffic - $node->traffic_lastday;
            // $traffic_today < 0 && $traffic_today =0;

            $obj = new SsNodeTrafficDaily();
            $obj->node_id = $node->id;
            $obj->u = 0;
            $obj->d = 0;
            $obj->total = $traffic_today ;
            $obj->traffic = flowAutoShow($traffic_today);
            $obj->save();

            #记录当前流量值
            $node->traffic_lastday = $node->traffic;
            # 写入每天流量差值记录
            $node->ipv6 = floor($traffic_today / 1073741824) . '.' . $node->ipv6;
            $node->ipv6 = substr($node->ipv6, 0, 20);
            # 计算每个节点的倍率 昨日流量 / 总体的流量
            if ($node->traffic_limit > 0) {
                $node->traffic_rate = round($traffic_today * 32 / $node->traffic_limit,1) ;
            }
            $node->traffic_rate > ($node->node_cost/5) && $node->traffic_rate = ($node->node_cost/5);

            # 若今天流量少于 16G，就写入禁用一次。
            if ($traffic_today < 16*1024*1024*1024) {
                $node->status = 0;
                $node->sort -= 1;
            }elseif ($traffic_today > 32*1024*1024*1024) {
              // code...
                $node->sort += 1;
            }

            // 如果今日流量 < 0 说明重置了。就把正数的sort变为0
            $traffic_today < 0 && $node->sort > 0 && $node->sort = 0;

            $node->save();
        }

        $jobEndTime = microtime(true);
        $jobUsedTime = round(($jobEndTime - $jobStartTime), 4);

        Log::info('执行定时任务【' . $this->description . '】，耗时' . $jobUsedTime . '秒');
/*
        //自动判断节点的状态
        $nodes_vnstat = SsNode::query()->get();
        $file = "public/".date("md");
        $node_line = '=====================================';
        $node_error = 'can not connect';
        $nodes_log = @file_put_contents($file, date("m-d H:i"));
        foreach ($nodes_vnstat as $node) {
            # code...
            $server = $node['server'] ? $node['server'] : $node['ip'];
            $server_ip = gethostbyname($server);
            $status_url = 'http://'.$server_ip.':'.$node['ssh_port'].'/status';
            $vnstat_url = 'http://'.$server_ip.':'.$node['ssh_port'].'/vnstat';
            $s1_url = 'http://'.$server_ip.':'.$node['ssh_port'].'/s1';
            $v2_url = 'http://'.$server_ip.':'.$node['ssh_port'].'/v2';
            $status = @file_get_contents($status_url);
            $vnstat = @file_get_contents($vnstat_url);
            $s1 = @file_get_contents($s1_url);
            $v2 = @file_get_contents($v2_url);
            //判断一下节点状态 7 = running restart
            if ($status == 7) {
                # code...
                SsNode::query()->where('id',$node['id'])->update(['status'=>1]);
                //将数据写入文件
                $data = $node['name']."#".$server."#".$server_ip."#".$node['status']."\n".$node_line."\n".$node['desc'].$vnstat."\n\n\n";
                $nodes_log = @file_put_contents($file, $data, FILE_APPEND);
            }elseif($status == 4 ) {
                # code...  4 = stop
                SsNode::query()->where('id',$node['id'])->update(['status'=>0]);
                //将数据写入文件
                $data = $node['name']."#".$server."#".$server_ip."#".$node['status']."\n".$node_line."\n".$node['desc'].$vnstat."\n\n\n";
                $nodes_log = @file_put_contents($file, $data, FILE_APPEND);
            }else {
                # code...  4 = stop
                SsNode::query()->where('id',$node['id'])->update(['status'=>0]);
                //同样写入数据，是获取不到运行状态
                $data = $node['name']."#".$server."#".$server_ip."#".$node['status'].$node_error."\n".$node_line."\n".$node['desc']."\n\n\n";
                $nodes_log = @file_put_contents($file, $data, FILE_APPEND);
            }
            //这里开始 全自动更改 后端节点的配置信息，可以有 这个可以有
            // 通过判断 $node['monitor_url'] 来判断是否是 单独那种节点
            if (!empty($node['monitor_url'])) { //如果不为空那么就代表着可用
                if ($node['type'] == 1 && !empty($s1)) {
                    # code...
                    $addn = explode('#',$s1);
                    $data = [
                        'ip'=>$addn['0'] ,
                        'ssh_port'=>$addn['1'],
                        'monitor_url'=>$addn['2'],
                        'method'=>$addn['3']
                    ];
                    SsNode::query()->where('id',$node['id'])->update($data);
                }
                # code...
                if ($node['type'] == 2 && !empty($v2)) {
                    # code...
                    $addn = explode('#',$v2);
                    $data = [
                        'ip'=>$addn['0'],
                        'v2_port'=>$addn['1'],
                        'v2_alter_id'=>$addn['2'],
                        'v2_net'=>$addn['3'],
                        'v2_type'=>$addn['4'],
                        'monitor_url'=>$addn['5']
                    ];
                    SsNode::query()->where('id',$node['id'])->update($data);
                }
            }
        }
        Log::info('执行定时任务【检查节点status状态】完成，结果已写入文件');
        */
    }

    private function statisticsByNode($node_id)
    {
        /*$start_time = strtotime(date('Y-m-d 00:00:00', strtotime("-1 day")));
        $end_time = strtotime(date('Y-m-d 23:59:59', strtotime("-1 day")));

        $query = UserTrafficLog::query()->where('node_id', $node_id)->whereBetween('log_time', [$start_time, $end_time]);
        //$query = SsNodeTrafficHourly::query()->where('node_id', $node_id)->whereBetween('log_time', [$start_time, $end_time]);

        $u = $query->sum('u');
        $d = $query->sum('d');
        $total = $u + $d;
        //获取节点信息
        $node = SsNode::query()->where('id', $node_id)->first();
        //如果倍率为0的话，无法做除数，改为最低倍率0.1
        //$node->traffic_rate < 0.1 && $node->traffic_rate = 0.1;
        //empty($node->monitor_url) && $total = $total / $node->traffic_rate;
        $traffic = flowAutoShow($total);

        //写入每日流量数据 有记录才会写 显得好看些
        if ( $total ) {
            # code...
            //写入每日流量数据
            $obj = new SsNodeTrafficDaily();
            $obj->node_id = $node_id;
            $obj->u = $u;
            $obj->d = $d;
            $obj->total = $total;
            $obj->traffic = $traffic;
            $obj->save();
        }

        // 在线节点少于 10G流量的隐藏 且节点名称加 -
        // 这个主要是用来证明节点是否可以正常使用的！
        if ($total < 10737418240) {
            $node->status = 0;
            $node->sort += 1;
        }
        //节点描述里，加上每日节点流量表现数值
        $node->desc = floor($total / 1073741824) . ' ' . $node->desc;
        $node->desc = substr($node->desc, 0, 32);

        $node->save();
        //SsNode::query()->where('id',$node_id)->update(['status'=>$node->status, 'ipv6'=>$node->ipv6 , 'desc'=>$node->desc ,'sort'=>$node->sort ]);
*/

    }
}

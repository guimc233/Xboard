import useSWR from 'swr'
import { Activity, CheckCircle2, Clock3, Database, ExternalLink, HardDrive, RefreshCw, ServerCog, ShieldCheck, XCircle } from 'lucide-react'
import { api } from '../lib/api'
import { formatDate } from '../lib/format'
import type { Machine, NodeRecord } from '../types'
import { Badge, Button, Card, ErrorState, LoadingTable, PageHeader } from '../components/ui'
import { SystemSettings } from '../components/SystemSettings'

interface SystemStatus {
  schedule: boolean
  horizon: boolean
  schedule_last_runtime?: number | null
}

interface QueueStats {
  failedJobs?: number
  jobsPerMinute?: number
  pausedMasters?: number
  processes?: number
  recentJobs?: number
  status?: boolean
}

function StatusRow({ label, healthy, description }: { label: string; healthy: boolean; description: string }) {
  return <div className="info-row"><span style={{ display: 'flex', alignItems: 'center', gap: 8 }}>{healthy ? <CheckCircle2 size={15} className="text-success" /> : <XCircle size={15} className="text-danger" />}{label}</span><span><Badge tone={healthy ? 'success' : 'danger'}>{healthy ? '正常' : '异常'}</Badge><small style={{ display: 'block', marginTop: 5 }} className="muted">{description}</small></span></div>
}

export function SystemPage() {
  const statusRequest = useSWR('system-status', () => api.adminGet<SystemStatus>('system/getSystemStatus'), { refreshInterval: 30_000 })
  const queueRequest = useSWR('queue-stats', () => api.adminGet<QueueStats>('system/getQueueStats'), { refreshInterval: 30_000 })
  const nodeRequest = useSWR('nodes', () => api.adminGet<NodeRecord[]>('server/manage/getNodes'))
  const machineRequest = useSWR('machines', () => api.adminGet<Machine[]>('server/machine/fetch'))
  const error = statusRequest.error || queueRequest.error
  const status = statusRequest.data
  const queue = queueRequest.data

  return (
    <>
      <PageHeader title="系统配置与状态" description="管理站点、订阅模板、节点通信与安全设置，并检查自托管分发组件。" actions={<Button variant="secondary" onClick={() => { void statusRequest.mutate(); void queueRequest.mutate(); void nodeRequest.mutate(); void machineRequest.mutate() }}><RefreshCw size={15} />刷新状态</Button>} />
      <SystemSettings />
      {error ? <ErrorState error={error} retry={() => { void statusRequest.mutate(); void queueRequest.mutate() }} /> : null}

      <div className="kpi-grid">
        <Card className="kpi-card"><div className="kpi-top"><span>任务调度</span><span className="kpi-icon"><Clock3 size={16} /></span></div><div className="kpi-value">{status?.schedule ? '正常' : '异常'}</div><div className="kpi-hint">最后运行 {formatDate(status?.schedule_last_runtime)}</div></Card>
        <Card className="kpi-card"><div className="kpi-top"><span>队列进程</span><span className="kpi-icon"><Activity size={16} /></span></div><div className="kpi-value">{queue?.processes ?? '--'}</div><div className="kpi-hint">{queue?.jobsPerMinute ?? 0} jobs / min</div></Card>
        <Card className="kpi-card"><div className="kpi-top"><span>节点总数</span><span className="kpi-icon"><HardDrive size={16} /></span></div><div className="kpi-value">{nodeRequest.data?.length ?? '--'}</div><div className="kpi-hint">多来源状态按节点聚合</div></Card>
        <Card className="kpi-card"><div className="kpi-top"><span>xbnode 服务器</span><span className="kpi-icon"><ServerCog size={16} /></span></div><div className="kpi-value">{machineRequest.data?.length ?? '--'}</div><div className="kpi-hint">Machine mode 机器身份</div></Card>
      </div>

      <div className="dashboard-grid">
        <Card>
          <div className="card-header"><div><h2>运行组件</h2><p>应用内部健康检查，每 30 秒自动刷新</p></div><Activity size={18} className="muted" /></div>
          {!status || !queue ? <LoadingTable rows={4} columns={2} /> : <div className="card-body info-list">
            <StatusRow label="Laravel Scheduler" healthy={Boolean(status.schedule)} description="维护统计、流量与周期任务" />
            <StatusRow label="Horizon Queue" healthy={Boolean(status.horizon && queue.status)} description={`${queue.processes || 0} 个工作进程，最近 ${queue.recentJobs || 0} 个任务`} />
            <StatusRow label="失败任务" healthy={!queue.failedJobs} description={`${queue.failedJobs || 0} 个近期失败任务`} />
            <StatusRow label="队列主控" healthy={!queue.pausedMasters} description={`${queue.pausedMasters || 0} 个已暂停 Master`} />
          </div>}
        </Card>

        <div style={{ display: 'grid', gap: 16, alignContent: 'start' }}>
          <Card>
            <div className="card-header"><div><h2>自托管构建</h2><p>本分支启用的分发能力</p></div><ShieldCheck size={18} className="muted" /></div>
            <div className="card-body info-list">
              <div className="info-row"><span>商业支付 API</span><span><Badge>已移除</Badge></span></div>
              <div className="info-row"><span>用户访问模型</span><span>权限组直接授权</span></div>
              <div className="info-row"><span>节点接入</span><span>node_id + machine</span></div>
              <div className="info-row"><span>多后端设备统计</span><span>来源快照并集</span></div>
              <div className="info-row"><span>代理核心</span><span>mihomo (Clash.Meta)</span></div>
            </div>
          </Card>
          <Card>
            <div className="card-header"><div><h2>快速入口</h2><p>前台和原始项目文档</p></div><Database size={18} className="muted" /></div>
            <div className="card-body" style={{ display: 'grid', gap: 8 }}>
              <a className="button button-secondary" href="/"><ExternalLink size={14} />打开用户前台</a>
              <a className="button button-secondary" href="https://github.com/cedar2025/Xboard" target="_blank" rel="noreferrer"><ExternalLink size={14} />Xboard 项目主页</a>
            </div>
          </Card>
        </div>
      </div>
    </>
  )
}

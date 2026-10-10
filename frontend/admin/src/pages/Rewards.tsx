import { Button, Card, message, Popconfirm, Space, Table, Tag } from 'antd'
import { useEffect, useState } from 'react'
import { api } from '../api'

export default function Rewards() {
  const [rows, setRows] = useState<any[]>([])
  const [loading, setLoading] = useState(false)

  const load = async () => {
    setLoading(true)
    try {
      const d = await api('/admin/rewards')
      setRows(d.list ?? [])
    } catch (e: any) {
      message.error(e.message)
    } finally {
      setLoading(false)
    }
  }
  useEffect(() => {
    load()
  }, [])

  const pay = async (rid: string) => {
    try {
      await api(`/admin/rewards/${rid}/pay`, { method: 'POST' })
      message.success('已标记打款')
      load()
    } catch (e: any) {
      message.error(e.message)
    }
  }

  const columns = [
    { title: '流水号', dataIndex: 'rid', width: 110 },
    { title: '用户', dataIndex: 'user_id', width: 110 },
    { title: '素材', dataIndex: 'material_title', ellipsis: true },
    { title: '类型', width: 120, render: (_: unknown, r: any) => (r.type === 'first' ? '首发奖励' : `数据奖励·第${r.round}轮`) },
    { title: '金额', dataIndex: 'amount', width: 90, render: (v: string) => <b style={{ color: '#cf1322' }}>¥{v}</b> },
    {
      title: '状态',
      dataIndex: 'status',
      width: 100,
      render: (s: string) => <Tag color={s === 'paid' ? 'green' : 'orange'}>{s === 'paid' ? '已打款' : '已入账'}</Tag>,
    },
    { title: '时间', dataIndex: 'created_at', width: 160 },
    {
      title: '操作',
      width: 120,
      render: (_: unknown, r: any) =>
        r.status === 'granted' ? (
          <Popconfirm title="确认已完成线下打款？" onConfirm={() => pay(r.rid)}>
            <Button size="small">标记已打款</Button>
          </Popconfirm>
        ) : (
          <span style={{ color: '#999' }}>{r.paid_at}</span>
        ),
    },
  ]

  return (
    <Card title="奖励记录（线下打款后请标记，便于对账）">
      <Space style={{ marginBottom: 12 }}>
        <Button onClick={load}>刷新</Button>
      </Space>
      <Table rowKey="rid" loading={loading} dataSource={rows} columns={columns as any} pagination={false} />
    </Card>
  )
}

import { Button, Card, Drawer, Form, Image, Input, InputNumber, message, Modal, Space, Table, Tabs, Tag } from 'antd'
import { useEffect, useState } from 'react'
import { api } from '../api'

const METRIC: Record<string, string> = { views: '播放', likes: '点赞', comments: '评论' }

function ReviewTable({ type }: { type: 'first' | 'stats' }) {
  const [rows, setRows] = useState<any[]>([])
  const [loading, setLoading] = useState(false)
  const [approve, setApprove] = useState<any>(null)
  const [amount, setAmount] = useState<number | null>(null)
  const [rejecting, setRejecting] = useState<any>(null)
  const [form] = Form.useForm()
  const [detail, setDetail] = useState<any>(null)

  const load = async () => {
    setLoading(true)
    try {
      const d = await api(`/admin/submissions?type=${type}`)
      setRows(d.list ?? [])
    } catch (e: any) {
      message.error(e.message)
    } finally {
      setLoading(false)
    }
  }
  useEffect(() => {
    load()
  }, [type])

  const doApprove = async () => {
    try {
      const res = await api(`/admin/submissions/${approve.sub_id}/review`, {
        method: 'POST',
        body: { action: 'approve', reward_amount: amount },
      })
      message.success(`已通过，任务进入「${res.task?.status === 'finished' ? '已完成' : res.task?.status}」`)
      setApprove(null)
      setAmount(null)
      load()
    } catch (e: any) {
      message.error(e.message)
    }
  }

  const doReject = async () => {
    const v = await form.validateFields()
    try {
      await api(`/admin/submissions/${rejecting.sub_id}/review`, {
        method: 'POST',
        body: { action: 'reject', reason: v.reason },
      })
      message.success('已驳回')
      setRejecting(null)
      form.resetFields()
      load()
    } catch (e: any) {
      message.error(e.message)
    }
  }

  const columns = [
    { title: '凭证', dataIndex: 'sub_id', width: 110 },
    { title: '用户', width: 150, render: (_: unknown, r: any) => `${r.user?.nickname}（${r.user?.phone}）` },
    { title: '素材', dataIndex: 'material_title', ellipsis: true },
    ...(type === 'first'
      ? [
          {
            title: '发布链接',
            dataIndex: 'platform_url',
            render: (u: string) =>
              u ? <a href={u} target="_blank" rel="noreferrer">打开</a> : '-',
          },
        ]
      : [
          { title: '轮次', dataIndex: 'round', width: 70 },
          {
            title: '自报数据',
            dataIndex: 'metrics',
            render: (m: any) =>
              m ? `${METRIC.views}:${m.views ?? 0} ${METRIC.likes}:${m.likes ?? 0} ${METRIC.comments}:${m.comments ?? 0}` : '-',
          },
        ]),
    {
      title: '截图',
      dataIndex: 'screenshots',
      width: 160,
      render: (shots: string[]) => (
        <Image.PreviewGroup>
          <Space>
            {(shots ?? []).map((s, i) => (
              <Image key={i} src={s} width={44} height={44} style={{ objectFit: 'cover', borderRadius: 4 }} />
            ))}
          </Space>
        </Image.PreviewGroup>
      ),
    },
    { title: '提交时间', dataIndex: 'created_at', width: 160 },
    {
      title: '操作',
      width: 160,
      render: (_: unknown, r: any) => (
        <Space>
          <Button
            size="small"
            type="primary"
            onClick={() => {
              setApprove(r)
              setAmount(null)
            }}
          >
            通过
          </Button>
          <Button size="small" danger onClick={() => setRejecting(r)}>
            驳回
          </Button>
          <a onClick={() => setDetail(r)}>详情</a>
        </Space>
      ),
    },
  ]

  return (
    <>
      <Table rowKey="sub_id" loading={loading} dataSource={rows} columns={columns as any} pagination={false} />
      <Modal
        title="审核通过"
        open={!!approve}
        onOk={doApprove}
        onCancel={() => setApprove(null)}
        destroyOnClose
      >
        {approve && (
          <div>
            <p>
              素材：<b>{approve.material_title}</b>
            </p>
            {approve.type === 'first' ? (
              <p>首发奖励规则：¥{approve.reward_cfg?.first ?? '0.00'}</p>
            ) : (
              <div style={{ marginBottom: 12 }}>
                数据档位：
                {(approve.reward_cfg?.stats_rules ?? []).map((r: any, i: number) => (
                  <Tag key={i}>
                    {METRIC[r.metric]}≥{r.gte} 奖¥{r.reward}
                  </Tag>
                ))}
                <div style={{ color: '#999', fontSize: 12, marginTop: 4 }}>
                  叠加：{approve.reward_cfg?.stack_mode === 'all' ? '命中叠加' : '取最高档'}；留空金额则按规则自动核定
                </div>
              </div>
            )}
            <InputNumber
              addonBefore="奖励金额（元）"
              min={0}
              step={0.5}
              precision={2}
              style={{ width: 260 }}
              value={amount}
              onChange={setAmount}
              placeholder="留空按规则"
            />
          </div>
        )}
      </Modal>
      <Modal title="驳回" open={!!rejecting} onOk={doReject} onCancel={() => setRejecting(null)} destroyOnClose>
        <Form form={form} layout="vertical">
          <Form.Item name="reason" label="驳回原因（用户可见）" rules={[{ required: true, message: '请填写原因' }]}>
            <Input.TextArea rows={3} placeholder="如：截图与链接内容不一致，请重新截图提交" />
          </Form.Item>
        </Form>
      </Modal>
      <Drawer title="凭证详情" open={!!detail} onClose={() => setDetail(null)} width={480}>
        {detail && (
          <div>
            <p>
              <b>{detail.material_title}</b>
            </p>
            <p style={{ color: '#666' }}>{detail.material_text}</p>
            <p>链接：{detail.platform_url || '-'}</p>
            <p>任务状态：{detail.task_status}</p>
            <Image.PreviewGroup>
              <Space wrap>
                {(detail.screenshots ?? []).map((s: string, i: number) => (
                  <Image key={i} src={s} width={140} />
                ))}
              </Space>
            </Image.PreviewGroup>
          </div>
        )}
      </Drawer>
    </>
  )
}

export default function Reviews() {
  return (
    <Card>
      <Tabs
        items={[
          { key: 'first', label: '首发凭证审核', children: <ReviewTable type="first" /> },
          { key: 'stats', label: '数据补发审核', children: <ReviewTable type="stats" /> },
        ]}
      />
    </Card>
  )
}

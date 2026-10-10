import { Button, Card, Descriptions, Drawer, Image, Input, message, Popconfirm, Select, Space, Table, Tag } from 'antd'
import type { Key } from 'react'
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../api'

const STATUS_TAG: Record<string, { color: string; text: string }> = {
  draft: { color: 'default', text: '草稿' },
  published: { color: 'green', text: '在架' },
  offline: { color: 'orange', text: '已下架' },
}

const METRIC: Record<string, string> = { views: '播放', likes: '点赞', comments: '评论' }

export default function Materials() {
  const [rows, setRows] = useState<any[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [kw, setKw] = useState('')
  const [status, setStatus] = useState('')
  const [rootCat, setRootCat] = useState<string>('')
  const [catId, setCatId] = useState<string>('')
  const [tag, setTag] = useState<string>('')
  const [cats, setCats] = useState<any[]>([])
  const [tags, setTags] = useState<any[]>([])
  const [selectedKeys, setSelectedKeys] = useState<Key[]>([])
  const [loading, setLoading] = useState(false)
  const [preview, setPreview] = useState<any>(null)
  const nav = useNavigate()

  useEffect(() => {
    Promise.all([api('/admin/categories'), api('/admin/tags')])
      .then(([c, t]) => {
        setCats(c.list ?? [])
        setTags(t.list ?? [])
      })
      .catch(() => {})
  }, [])

  const roots = cats.filter((c) => c.parent_id === '0')
  const rootName = (id: string) => roots.find((r) => r.id === id)?.name ?? ''
  // 联动：选了一级只列其二级；未选则列出全部二级（带一级前缀）
  const catOptions = (rootCat
    ? cats.filter((c) => c.parent_id === rootCat)
    : cats.filter((c) => c.parent_id !== '0')
  ).map((c) => ({ value: c.id, label: rootCat ? c.name : `${rootName(c.parent_id)} / ${c.name}` }))

  const load = async (p = page) => {
    setLoading(true)
    try {
      const q = new URLSearchParams({ page: String(p), page_size: '20' })
      if (kw) q.set('keyword', kw)
      if (status) q.set('status', status)
      if (catId) q.set('category_id', catId)
      if (tag) q.set('tag', tag)
      const data = await api(`/admin/materials?${q}`)
      setRows(data.list ?? [])
      setTotal(data.total ?? 0)
    } catch (e: any) {
      message.error(e.message)
    } finally {
      setLoading(false)
    }
  }
  useEffect(() => {
    load(1)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const search = () => {
    setPage(1)
    load(1)
  }
  const reset = () => {
    setKw(''); setStatus(''); setRootCat(''); setCatId(''); setTag('')
    setPage(1)
    load(1)
  }

  const act = async (fn: () => Promise<any>, ok: string) => {
    try {
      await fn()
      message.success(ok)
      load()
    } catch (e: any) {
      message.error(e.message)
    }
  }

  const openPreview = async (id: string) => {
    try {
      setPreview(await api(`/admin/materials/${id}`))
    } catch (e: any) {
      message.error(e.message)
    }
  }

  const copyText = async (text: string) => {
    try {
      await navigator.clipboard.writeText(text)
      message.success('文案已复制')
    } catch {
      message.error('复制失败，请手动选择复制')
    }
  }

  const OP_NAME: Record<string, string> = { publish: '上架', offline: '下架', delete: '删除' }
  const batch = async (op: 'publish' | 'offline' | 'delete') => {
    let okN = 0
    let failN = 0
    const errs: string[] = []
    for (const id of selectedKeys as string[]) {
      try {
        if (op === 'delete') await api(`/admin/materials/${id}`, { method: 'DELETE' })
        else await api(`/admin/materials/${id}/${op}`, { method: 'POST' })
        okN++
      } catch (e: any) {
        failN++
        if (errs.length < 2) errs.push(`${id}: ${e.message}`)
      }
    }
    if (failN === 0) message.success(`批量${OP_NAME[op]}成功（${okN} 项）`)
    else message.warning(`成功 ${okN} 项，失败 ${failN} 项：${errs.join('；')}`)
    setSelectedKeys([])
    load()
  }

  const columns = [
    { title: 'ID', dataIndex: 'id', width: 100 },
    { title: '标题', dataIndex: 'title', ellipsis: true, width: 220 },
    { title: '分类', dataIndex: 'category_name', width: 110 },
    {
      title: '标签',
      dataIndex: 'tag_keys',
      width: 140,
      render: (keys: string[]) => keys?.map((k) => <Tag key={k}>{k}</Tag>),
    },
    { title: '类型', dataIndex: 'type', width: 90, render: (t: string) => (t === 'image_text' ? '图文' : '视频+文字') },
    {
      title: '状态',
      dataIndex: 'status',
      width: 80,
      render: (s: string) => <Tag color={STATUS_TAG[s]?.color}>{STATUS_TAG[s]?.text ?? s}</Tag>,
    },
    { title: '首奖', dataIndex: ['reward', 'first'], width: 80, render: (v: string) => `¥${v}` },
    {
      title: '领取/提交/通过',
      width: 120,
      render: (_: unknown, r: any) => `${r.stats?.claim ?? 0} / ${r.stats?.submit ?? 0} / ${r.stats?.approve ?? 0}`,
    },
    {
      title: '剩余配额',
      width: 90,
      render: (_: unknown, r: any) => (r.stats?.quota_left < 0 ? '不限' : r.stats?.quota_left ?? '-'),
    },
    {
      title: '操作',
      width: 230,
      fixed: 'right' as const,
      render: (_: unknown, r: any) => (
        <Space size="small">
          <a onClick={() => openPreview(r.id)}>预览</a>
          <a onClick={() => nav(`/materials/${r.id}/edit`)}>编辑</a>
          {r.status !== 'published' ? (
            <a onClick={() => act(() => api(`/admin/materials/${r.id}/publish`, { method: 'POST' }), '已上架')}>上架</a>
          ) : (
            <a onClick={() => act(() => api(`/admin/materials/${r.id}/offline`, { method: 'POST' }), '已下架')}>下架</a>
          )}
          <Popconfirm
            title="确认删除？"
            onConfirm={() => act(() => api(`/admin/materials/${r.id}`, { method: 'DELETE' }), '已删除')}
          >
            <a style={{ color: '#cf1322' }}>删除</a>
          </Popconfirm>
        </Space>
      ),
    },
  ]

  return (
    <div>
      <Space wrap style={{ marginBottom: 12 }}>
        <Input
          placeholder="按标题搜索"
          allowClear
          value={kw}
          onChange={(e) => setKw(e.target.value)}
          onPressEnter={search}
          style={{ width: 180 }}
        />
        <Select
          placeholder="一级分类"
          allowClear
          style={{ width: 130 }}
          value={rootCat || undefined}
          onChange={(v) => { setRootCat(v ?? ''); setCatId('') }}
          options={roots.map((r) => ({ value: r.id, label: r.name }))}
        />
        <Select
          placeholder={rootCat ? '二级分类' : '二级分类（可选一级联动）'}
          allowClear
          style={{ width: 190 }}
          value={catId || undefined}
          onChange={(v) => setCatId(v ?? '')}
          options={catOptions}
        />
        <Select
          placeholder="标签"
          allowClear
          style={{ width: 120 }}
          value={tag || undefined}
          onChange={(v) => setTag(v ?? '')}
          options={tags.map((t) => ({ value: t.key, label: t.name }))}
        />
        <Select
          placeholder="状态"
          allowClear
          style={{ width: 110 }}
          value={status || undefined}
          onChange={(v) => setStatus(v ?? '')}
          options={[
            { value: 'draft', label: '草稿' },
            { value: 'published', label: '在架' },
            { value: 'offline', label: '已下架' },
          ]}
        />
        <Button type="primary" onClick={search}>查询</Button>
        <Button onClick={reset}>重置</Button>
        <Button type="primary" onClick={() => nav('/materials/new')}>新建素材</Button>
      </Space>

      {selectedKeys.length > 0 && (
        <Space style={{ marginBottom: 12, padding: '8px 12px', background: '#f0f7ff', borderRadius: 6, width: '100%' }}>
          <span>已选 <b>{selectedKeys.length}</b> 项</span>
          <Button size="small" type="primary" onClick={() => batch('publish')}>批量上架</Button>
          <Button size="small" onClick={() => batch('offline')}>批量下架</Button>
          <Popconfirm title={`确认删除所选 ${selectedKeys.length} 项？（在架素材需先批量下架）`} onConfirm={() => batch('delete')}>
            <Button size="small" danger>批量删除</Button>
          </Popconfirm>
          <Button size="small" type="text" onClick={() => setSelectedKeys([])}>取消选择</Button>
        </Space>
      )}

      <Table
        rowKey="id"
        loading={loading}
        columns={columns as any}
        dataSource={rows}
        scroll={{ x: 1180 }}
        rowSelection={{ selectedRowKeys: selectedKeys, onChange: (k) => setSelectedKeys(k) }}
        pagination={{ current: page, total, pageSize: 20, onChange: (p) => { setPage(p); load(p) } }}
      />

      <Drawer
        title={preview?.title ?? '素材预览'}
        open={!!preview}
        onClose={() => setPreview(null)}
        width={560}
        destroyOnClose
      >
        {preview && (
          <div>
            <Space wrap style={{ marginBottom: 12 }}>
              <Tag color={STATUS_TAG[preview.status]?.color}>{STATUS_TAG[preview.status]?.text ?? preview.status}</Tag>
              {preview.tag_keys?.map((k: string) => <Tag key={k} color="blue">{k}</Tag>)}
              <span style={{ color: '#999' }}>{preview.category_name ?? preview.category_id}</span>
            </Space>
            {preview.images?.length > 0 && (
              <Image.PreviewGroup>
                <Space direction="vertical" style={{ width: '100%' }}>
                  {preview.images.map((u: string, i: number) => (
                    <Image key={i} src={u} style={{ borderRadius: 6 }} />
                  ))}
                </Space>
              </Image.PreviewGroup>
            )}
            {preview.video && <video src={preview.video} controls style={{ width: '100%', borderRadius: 6 }} />}
            <Card size="small" title="发布文案" style={{ marginTop: 12 }}
              extra={<a onClick={() => copyText(preview.text ?? '')}>复制</a>}>
              <div style={{ whiteSpace: 'pre-wrap' }}>{preview.text}</div>
            </Card>
            {preview.notice && (
              <Card size="small" title="发布说明" style={{ marginTop: 12 }}>
                <div style={{ whiteSpace: 'pre-wrap', color: '#666' }}>{preview.notice.replace(/^#+\s*/gm, '')}</div>
              </Card>
            )}
            <Card size="small" title="奖励规则" style={{ marginTop: 12 }}>
              <div>首发奖励：<b style={{ color: '#cf1322' }}>¥{preview.reward?.first ?? '0.00'}</b></div>
              {(preview.reward?.stats_rules ?? []).length > 0 && (
                <div style={{ marginTop: 6 }}>
                  {preview.reward.stats_rules.map((r: any, i: number) => (
                    <Tag key={i}>{METRIC[r.metric]}≥{r.gte} 奖 ¥{r.reward}</Tag>
                  ))}
                  <div style={{ color: '#999', fontSize: 12, marginTop: 4 }}>
                    {preview.reward?.stack_mode === 'all' ? '命中叠加' : '取最高档'} · 最多补发 {preview.reward?.max_bonus_rounds ?? 0} 轮
                  </div>
                </div>
              )}
            </Card>
            <Descriptions size="small" column={2} style={{ marginTop: 12 }}
              items={[
                { key: '1', label: '领取', children: preview.stats?.claim ?? 0 },
                { key: '2', label: '提交', children: preview.stats?.submit ?? 0 },
                { key: '3', label: '通过', children: preview.stats?.approve ?? 0 },
                { key: '4', label: '剩余配额', children: preview.stats?.quota_left < 0 ? '不限' : preview.stats?.quota_left ?? '-' },
                { key: '5', label: '每人限领', children: preview.per_user_limit ?? 1 },
                { key: '6', label: '提交时限', children: `${preview.window_hours ?? 48} 小时` },
              ]}
            />
          </div>
        )}
      </Drawer>
    </div>
  )
}

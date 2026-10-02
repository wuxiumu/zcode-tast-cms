import { Button, Card, Form, Input, InputNumber, message, Radio, Select, Space, Upload } from 'antd'
import type { UploadFile } from 'antd'
import { useEffect, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { api, upload } from '../api'

/**
 * 图片上传（受控，value 为 url 数组）。
 * 注意：fileList 必须由组件内部状态持有（保留 antd 的 uid/上传中状态/本地缩略图），
 * 不能每次渲染从 value 重建——否则上传中的项会被过滤丢弃、完成后整表重建导致不显示图片。
 */
function ImgsUpload({ value = [], onChange, max = 9 }: { value?: string[]; onChange?: (v: string[]) => void; max?: number }) {
  const [files, setFiles] = useState<UploadFile<any>[]>(() =>
    value.map((u, i) => ({ uid: `init-${i}`, name: u, status: 'done' as const, url: u })),
  )
  // 记录最近一次向上抛出的 url 列表，区分「内部上传引发的变化」与「外部加载（编辑/重置）」
  const emitted = useRef<string>(JSON.stringify(value))

  useEffect(() => {
    const sig = JSON.stringify(value)
    if (sig !== emitted.current) {
      emitted.current = sig
      setFiles(value.map((u, i) => ({ uid: `init-${i}`, name: u, status: 'done' as const, url: u })))
    }
  }, [value])

  return (
    <Upload
      listType="picture-card"
      accept="image/*"
      maxCount={max}
      fileList={files}
      customRequest={async ({ file, onSuccess, onError }) => {
        try {
          const url = await upload(file as File, 'image')
          onSuccess?.({ data: { url } } as any)
        } catch (e) {
          onError?.(e as Error)
        }
      }}
      onChange={({ fileList: fl }) => {
        setFiles(fl)
        const urls = fl
          .filter((f) => f.status === 'done' && (f.url || (f.response as any)?.data?.url))
          .map((f) => f.url ?? (f.response as any).data.url)
        emitted.current = JSON.stringify(urls)
        onChange?.(urls)
      }}
    >
      {files.length < max && <div>+ 上传</div>}
    </Upload>
  )
}

function VideoUpload({ value, onChange }: { value?: string; onChange?: (v: string) => void }) {
  return (
    <div>
      <Upload
        accept="video/mp4,video/quicktime"
        maxCount={1}
        showUploadList={false}
        customRequest={async ({ file, onSuccess, onError }) => {
          try {
            const url = await upload(file as File, 'video')
            onChange?.(url)
            onSuccess?.({} as any)
          } catch (e) {
            onError?.(e as Error)
          }
        }}
      >
        <Button>{value ? '重新上传视频' : '上传视频（≤200MB，mp4/mov）'}</Button>
      </Upload>
      {value && (
        <div style={{ marginTop: 8 }}>
          <video src={value} controls style={{ maxWidth: 320, borderRadius: 6 }} />
        </div>
      )}
    </div>
  )
}

export default function MaterialEdit() {
  const { id } = useParams()
  const nav = useNavigate()
  const [form] = Form.useForm()
  const [type, setType] = useState<string>('image_text')
  const [cats, setCats] = useState<any[]>([])
  const [tags, setTags] = useState<any[]>([])
  const [rootCat, setRootCat] = useState<string>('')
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    Promise.all([api('/admin/categories'), api('/admin/tags')]).then(([c, t]) => {
      const cl: any[] = c.list ?? []
      setCats(cl)
      setTags((t.list ?? []).filter((x: any) => x.status === 'on'))
      if (id) {
        // 编辑：等分类列表就绪后回显，并联动定位一级分类
        api(`/admin/materials/${id}`).then((m) => {
          setType(m.type)
          form.setFieldsValue({
            ...m,
            reward: {
              ...m.reward,
              first: m.reward?.first ? parseFloat(m.reward.first) : 0,
              stats_rules: (m.reward?.stats_rules ?? []).map((r: any) => ({
                ...r,
                reward: parseFloat(r.reward),
              })),
            },
            notice: m.notice,
          })
          setRootCat(cl.find((c) => c.id === m.category_id)?.parent_id ?? '')
        })
      }
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  const rootOptions = cats.filter((c) => c.parent_id === '0').map((c) => ({ value: c.id, label: c.name }))
  // 联动：二级分类选项跟随一级
  const secondOptions = cats
    .filter((c) => c.parent_id === (rootCat || '__none__'))
    .map((c) => ({ value: c.id, label: c.name }))

  const save = async (autoPublish: boolean) => {
    const v = await form.validateFields()
    setSaving(true)
    try {
      const body = {
        ...v,
        auto_publish: autoPublish,
        reward: {
          ...v.reward,
          first: String(v.reward.first ?? 0),
          stats_rules: (v.reward.stats_rules ?? []).map((r: any) => ({ ...r, reward: String(r.reward ?? 0) })),
        },
      }
      if (id) {
        await api(`/admin/materials/${id}`, { method: 'PUT', body })
        if (autoPublish) await api(`/admin/materials/${id}/publish`, { method: 'POST' })
        message.success('已保存')
      } else {
        const created = await api('/admin/materials', { method: 'POST', body })
        message.success(autoPublish ? '已保存并上架' : '已保存草稿')
        void created
      }
      nav('/materials')
    } catch (e: any) {
      if (e?.errorFields) return
      message.error(e.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card
      className="edit-card"
      title={id ? `编辑素材 ${id}` : '新建素材'}
      extra={
        <Space>
          {id && (
            <Button onClick={() => save(false)} loading={saving}>
              保存
            </Button>
          )}
          <Button type="primary" onClick={() => save(true)} loading={saving}>
            {id ? '保存并上架' : '保存并上架'}
          </Button>
          <Button onClick={() => (id ? nav('/materials') : save(false))}>{id ? '返回' : '仅存草稿'}</Button>
        </Space>
      }
    >
      <Form form={form} layout="vertical" style={{ maxWidth: 760 }} initialValues={{ type: 'image_text', quota: -1, per_user_limit: 1, window_hours: 48, reward: { first: 0, stack_mode: 'highest', max_bonus_rounds: 0, stats_rules: [] } }}>
        <Card type="inner" title="基本信息" style={{ marginBottom: 16 }}>
          <Form.Item name="title" label="标题" rules={[{ required: true, message: '请输入标题' }]}>
            <Input placeholder="如：蛋仔派对夏日皮肤安利" />
          </Form.Item>
          <Form.Item name="type" label="素材类型" rules={[{ required: true }]}>
            <Radio.Group
              onChange={(e) => setType(e.target.value)}
              options={[
                { value: 'image_text', label: '图文' },
                { value: 'video_text', label: '视频 + 文字' },
              ]}
            />
          </Form.Item>
          <Form.Item label="一级分类">
            <Select
              allowClear
              placeholder="选择一级分类"
              value={rootCat || undefined}
              onChange={(v) => {
                setRootCat(v ?? '')
                form.setFieldValue('category_id', undefined)
              }}
              options={rootOptions}
            />
          </Form.Item>
          <Form.Item name="category_id" label="二级分类" rules={[{ required: true, message: '请选择二级分类' }]}>
            <Select
              showSearch
              optionFilterProp="label"
              placeholder={rootCat ? '选择二级分类' : '请先选择一级分类'}
              options={secondOptions}
            />
          </Form.Item>
          <Form.Item
            name="tag_keys"
            label="标签（渠道，可多选 · 预留标签位）"
            rules={[{ required: true, message: '至少选一个标签' }]}
          >
            <Select mode="multiple" placeholder="选择发布渠道" options={tags.map((t) => ({ value: t.key, label: t.name }))} />
          </Form.Item>
        </Card>

        <Card type="inner" title="内容" style={{ marginBottom: 16 }}>
          {type === 'image_text' ? (
            <Form.Item name="images" label="图片（1~9 张，第一张默认作封面）" rules={[{ required: true, message: '请上传图片' }]}>
              <ImgsUpload />
            </Form.Item>
          ) : (
            <Form.Item name="video" label="视频" rules={[{ required: true, message: '请上传视频' }]}>
              <VideoUpload />
            </Form.Item>
          )}
          <Form.Item name="cover" label="封面（可选，默认取第一张图）">
            <Input placeholder="/uploads/..." />
          </Form.Item>
          <Form.Item name="text" label="发布文案（用户一键复制）" rules={[{ required: true, message: '请输入文案' }]}>
            <Input.TextArea rows={4} placeholder="#话题  正文…" />
          </Form.Item>
          <Form.Item name="notice" label="发布说明/注意事项（展示给用户）">
            <Input.TextArea rows={3} placeholder={'- 必须带话题\n- 图片按顺序发布'} />
          </Form.Item>
        </Card>

        <Card type="inner" title="奖励规则" style={{ marginBottom: 16 }}>
          <Form.Item label="首发奖励（元）" name={['reward', 'first']} rules={[{ required: true }]}>
            <InputNumber min={0} step={0.5} precision={2} style={{ width: 160 }} />
          </Form.Item>
          <Form.List name={['reward', 'stats_rules']}>
            {(fields, { add, remove }) => (
              <>
                {fields.map((f) => (
                  <Space key={f.key} align="baseline" style={{ display: 'flex', marginBottom: 4 }}>
                    <Form.Item name={[f.name, 'metric']} rules={[{ required: true }]} style={{ marginBottom: 0 }}>
                      <Select
                        style={{ width: 130 }}
                        options={[
                          { value: 'views', label: '播放量' },
                          { value: 'likes', label: '点赞量' },
                          { value: 'comments', label: '评论量' },
                        ]}
                      />
                    </Form.Item>
                    <span>≥</span>
                    <Form.Item name={[f.name, 'gte']} rules={[{ required: true }]} style={{ marginBottom: 0 }}>
                      <InputNumber min={0} placeholder="阈值" style={{ width: 120 }} />
                    </Form.Item>
                    <span>奖</span>
                    <Form.Item name={[f.name, 'reward']} rules={[{ required: true }]} style={{ marginBottom: 0 }}>
                      <InputNumber min={0} step={0.5} precision={2} placeholder="元" style={{ width: 120 }} />
                    </Form.Item>
                    <a onClick={() => remove(f.name)} style={{ color: '#cf1322' }}>
                      删除
                    </a>
                  </Space>
                ))}
                <Button size="small" onClick={() => add({ metric: 'views', gte: 1000, reward: 1 })}>
                  + 添加数据档位
                </Button>
              </>
            )}
          </Form.List>
          <Space style={{ marginTop: 16 }}>
            <Form.Item label="叠加方式" name={['reward', 'stack_mode']} style={{ marginBottom: 0 }}>
              <Radio.Group
                options={[
                  { value: 'highest', label: '取命中最高档' },
                  { value: 'all', label: '命中叠加' },
                ]}
              />
            </Form.Item>
            <Form.Item label="数据补发上限（轮，0=关闭）" name={['reward', 'max_bonus_rounds']} style={{ marginBottom: 0 }}>
              <InputNumber min={0} max={10} />
            </Form.Item>
          </Space>
        </Card>

        <Card type="inner" title="发布配置" style={{ marginBottom: 16 }}>
          <Space size="large">
            <Form.Item label="领取配额（-1 不限）" name="quota">
              <InputNumber min={-1} style={{ width: 140 }} />
            </Form.Item>
            <Form.Item label="每人限领" name="per_user_limit">
              <InputNumber min={1} style={{ width: 100 }} />
            </Form.Item>
            <Form.Item label="提交时限（小时）" name="window_hours">
              <InputNumber min={1} style={{ width: 120 }} />
            </Form.Item>
          </Space>
        </Card>
      </Form>
    </Card>
  )
}
